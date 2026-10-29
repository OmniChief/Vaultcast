"""Regression tests for interrupted contributor uploads; no NAS or FFmpeg required."""
import io
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch
import urllib.error
import encoding_worker as encoding
import worker


def http_error(code):
    return urllib.error.HTTPError('http://test/chunk', code, 'test', {}, io.BytesIO(b''))


class UploadTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.output = Path(self.temp.name) / 'output.mp4'
        self.content = b'a' * 1000 + b'b' * 1000 + b'c' * 1000
        self.output.write_bytes(self.content)
        self.server = bytearray()
        self.offsets = []
        self.progress = []
        self.uploader = Mock()
        self.lost = threading.Event()
        self.wait = patch.object(encoding, 'transfer_wait')
        self.wait.start()
        self.addCleanup(self.wait.stop)

    def send(self, chunk, offset, callback):
        self.offsets.append(offset)
        self.assertEqual(offset, len(self.server))
        self.server.extend(chunk)
        callback(offset + len(chunk))

    def upload(self, send=None, status=None):
        self.uploader.send.side_effect = send or self.send
        return encoding.upload_file(self.output, len(self.content), 0, 1000, self.uploader,
            status or (lambda: {'received': len(self.server)}),
            lambda done, *args: self.progress.append(done), {}, self.lost, Mock())

    def test_lost_last_ack_does_not_resend_tail(self):
        def send(*args):
            self.send(*args)
            if len(self.server) == len(self.content):
                raise TimeoutError('ack lost')
        self.upload(send)
        self.assertEqual(self.offsets, [0, 1000, 2000])
        self.assertEqual(self.server, self.content)
        self.assertEqual(self.progress[-1], len(self.content))

    def test_partial_append_resumes_at_confirmed_offset(self):
        def send(chunk, offset, callback):
            if not self.server:
                self.offsets.append(offset)
                self.server.extend(chunk[:350])
                raise ConnectionResetError()
            self.send(chunk, offset, callback)
        self.upload(send)
        self.assertEqual(self.offsets[1], 350)
        self.assertEqual(self.server, self.content)

    def test_timeout_before_write_retries_same_offset(self):
        calls = [0]
        def send(*args):
            calls[0] += 1
            if calls[0] == 1:
                raise TimeoutError()
            self.send(*args)
        self.upload(send)
        self.assertEqual(self.server, self.content)

    def test_rate_limit_retries_after_status_check(self):
        calls = [0]
        def send(*args):
            calls[0] += 1
            if calls[0] == 1:
                raise http_error(429)
            self.send(*args)
        self.upload(send)
        self.assertEqual(self.server, self.content)

    def test_repeated_timeout_is_bounded(self):
        with self.assertRaises(TimeoutError):
            self.upload(Mock(side_effect=TimeoutError()))
        self.assertEqual(self.uploader.send.call_count, 5)

    def test_auth_failure_is_not_retried(self):
        with self.assertRaises(urllib.error.HTTPError):
            self.upload(Mock(side_effect=http_error(401)))
        self.assertEqual(self.uploader.send.call_count, 1)

    def test_bad_server_offset_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'unexpected upload offset'):
            self.upload(Mock(side_effect=TimeoutError()), lambda: {'received': 999999})

    def test_cancel_interrupts_before_upload(self):
        self.lost.set()
        with self.assertRaises(RuntimeError):
            self.upload()
        self.uploader.send.assert_not_called()

    def test_status_retries_transient_failures(self):
        request = Mock(side_effect=[TimeoutError(), http_error(503), encoding.InvalidServerResponse('HTML'), {'received': 5}])
        result = encoding.retry_request(request, 'http://test/status', {}, {}, {}, self.lost)
        self.assertEqual(result, {'received': 5})
        self.assertEqual(request.call_count, 4)

    def test_non_json_response_is_recoverable_and_diagnostic(self):
        response = Mock()
        response.read.return_value = b'<html>gateway error</html>'
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        with patch.object(worker.urllib.request, 'urlopen', return_value=response):
            with self.assertRaises(encoding.InvalidServerResponse) as raised:
                worker.request('http://test/status', 'POST', {})
        self.assertTrue(encoding.retryable_upload(raised.exception))
        self.assertIn('POST /status', str(raised.exception))

    def test_completed_server_state_stops_upload(self):
        result = self.upload(Mock(side_effect=TimeoutError()), lambda: {'uploaded': True, 'replaced': True})
        self.assertTrue(result['uploaded'])
        self.assertEqual(self.uploader.send.call_count, 1)

    def test_proxy_body_limit_reduces_chunk_size(self):
        content = b'x' * 800000
        self.output.write_bytes(content)
        offsets = []
        def send(chunk, offset, callback):
            if len(chunk) > 300000:
                raise http_error(413)
            offsets.append(offset)
            self.assertEqual(offset, len(self.server))
            self.server.extend(chunk)
        self.uploader.send.side_effect = send
        encoding.upload_file(self.output, len(content), 0, 800000, self.uploader,
            lambda: {'received':len(self.server)}, Mock(), {}, self.lost, Mock())
        self.assertEqual(self.server, content)
        self.assertEqual(offsets[0], 0)

    def test_chunk_non_json_ack_closes_connection(self):
        uploader = encoding.ChunkUploader('http://test/chunk', {})
        connection = Mock()
        uploader.connection = connection
        response = connection.getresponse.return_value
        response.status = 200
        response.read.return_value = b'<html>timeout</html>'
        with self.assertRaises(encoding.InvalidServerResponse):
            uploader.send(b'abc', 0, Mock())
        connection.close.assert_called_once()
        connection.sock.settimeout.assert_any_call(120)

    def test_chunk_short_ack_is_recoverable(self):
        uploader = encoding.ChunkUploader('http://test/chunk', {})
        uploader.connection = Mock()
        response = uploader.connection.getresponse.return_value
        response.status = 200
        response.read.return_value = b'{"received": 2}'
        with self.assertRaises(encoding.InvalidServerResponse):
            uploader.send(b'abc', 0, Mock())

    def test_lost_completion_response_does_not_repeat_completion(self):
        cfg = {'server':'http://test', 'worker_id':'worker', 'worker_token':'token'}
        job = {'id':'a'*16, 'lease':'lease', 'bytes':1000, 'title':'test'}
        workspace = Path(self.temp.name) / job['id']
        workspace.mkdir()
        (workspace / 'directplay.mp4').write_bytes(b'x'*1500)
        (workspace / 'state.json').write_text(json.dumps({'job':job, 'output_ready':True, 'output_size':1500}))
        complete = []
        def request(url, method, data, headers, timeout):
            if url.endswith('/transfer'):
                return {'granted':True}
            if url.endswith('/complete'):
                complete.append(url)
                raise TimeoutError('completion response lost')
            if url.endswith('/status'):
                return {'uploaded':True, 'replaced':True} if complete else {'received':1500}
            return {}
        with patch.object(encoding, 'transfer_root', return_value=Path(self.temp.name)), patch.object(encoding, 'ConsoleProgress'):
            encoding.run_encoding(cfg, job, request)
        self.assertEqual(len(complete), 1)
        self.assertFalse(workspace.exists())


if __name__ == '__main__':
    unittest.main()
