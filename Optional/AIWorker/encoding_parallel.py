"""Two bounded hardware checkpoint lanes sharing the worker's CPU allocation."""
from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED
import threading


def encode_batch(tasks, cfg, lost, root, duration, status, completed, encode, check, saved):
    cancel = threading.Event()
    cores = list(cfg["cpu_cores"])
    # Both sections share the worker's full affinity pool. Restricting each
    # section to one processor also restricted its audio and driver threads,
    # and silently discarded every allocated processor after the first two.
    # The shared affinity still caps their combined CPU use to this worker's
    # allocation; divide codec/filter thread budgets across the active sections.
    threads = max(1, len(cores) // max(1, len(tasks)))
    initial_work = float(status.get("encode_work_seconds", 0))
    states = []
    pool = ThreadPoolExecutor(max_workers=2, thread_name_prefix="gpu-section")
    futures = {}
    try:
        for lane, (index, seconds, command) in enumerate(tasks):
            work = root / ("gpu-lane-" + str(lane))
            work.mkdir(exist_ok=True)
            (work / "progress").unlink(missing_ok=True)
            command = list(command)
            command[command.index("-progress") + 1] = str(work / "progress")
            for at, value in enumerate(command[:-1]):
                if value in ("-threads", "-filter_threads"):
                    command[at + 1] = str(threads)
            local = dict(cfg, cpu_cores=cores, encoding_threads=threads, _stop_event=cancel)
            progress = {"encode_work_seconds": 0}
            states.append(progress)
            future = pool.submit(encode, command, local, lost, work, seconds, progress)
            futures[future] = index
        while futures:
            finished, _ = wait(futures, timeout=0.25, return_when=FIRST_COMPLETED)
            failures = []
            for future in finished:
                index = futures.pop(future)
                try:
                    future.result()
                except Exception as error:
                    failures.append(error)
                    continue
                # Persist each successful section immediately, even if its peer
                # later fails or the worker is interrupted.
                saved(index)
            if failures:
                raise failures[0]
            check(cfg, lost)
            fresh = sum(float(item.get("encode_work_seconds", 0)) for item in states)
            done = min(duration, completed + fresh)
            status.update(encode_work_seconds=initial_work + fresh,
                          encode_remaining_seconds=max(0, duration-done),
                          progress=round(10 + done/duration*80, 1),
                          phase_progress=round(done/duration*100, 1))
    finally:
        cancel.set()
        pool.shutdown(wait=True, cancel_futures=True)
