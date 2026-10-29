"""Pair through Vaultcast's existing login and one-time pairing endpoints."""
import http.cookiejar
import json
import re
import socket
import urllib.error
import urllib.parse
import urllib.request


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError("Vaultcast redirected the request. Enter its final address before signing in.")


def server_address(value):
    value = value.strip().rstrip("/")
    parts = urllib.parse.urlsplit(value)
    if parts.scheme not in ("https", "http") or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError("Enter a full Vaultcast HTTP or HTTPS address without passwords or query parameters.")
    return value


def pair(config, server, username="", password="", code=""):
    server = server_address(server)
    if not config.get("slots"):
        raise ValueError("Configure at least one worker before pairing this computer.")
    cookies = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(NoRedirects(), urllib.request.HTTPCookieProcessor(cookies))

    def post(path, body):
        req = urllib.request.Request(server + "/api" + path, data=json.dumps(body).encode(),
                                     headers={"Content-Type": "application/json", "Accept": "application/json"}, method="POST")
        try:
            with opener.open(req, timeout=30) as response:
                value = json.loads(response.read(1048576).decode("utf-8"))
                if not isinstance(value, dict):
                    raise ValueError("Vaultcast returned an unexpected response.")
                return value
        except urllib.error.HTTPError as error:
            # Do not include request bodies, credentials or session tokens in errors/logs.
            messages = {401: "Sign-in failed. Check your username and password.",
                        403: "Your account is not allowed to pair this worker.",
                        422: "Pairing was rejected. Check account access, worker setup or use a fresh pairing code.",
                        429: "Too many attempts. Wait before trying again."}
            raise ValueError(messages.get(error.code, "Vaultcast could not complete pairing (HTTP %s)." % error.code)) from None

    signed_in = False
    try:
        if not code:
            if not username.strip() or not password:
                raise ValueError("Enter your Vaultcast username and password.")
            post("/login", {"username": username.strip(), "password": password})
            signed_in = True
            code = post("/ai/pair", {})["pair_code"]
        code = "".join(code.split()).lower()
        if not re.fullmatch(r"[a-f0-9]{32}", code):
            raise ValueError("Enter the 32-character one-time pairing code from Vaultcast.")
        import worker
        from contributor_setup import model_report
        return post("/ai/worker/register", {
            "pair_code": code, "name": socket.gethostname(),
            "model_name": config.get("model_name", ""),
            "model_base": "sdxl" if config.get("contributions", {}).get("images") else "",
            "models": model_report(config.get("models", [])),
            "capabilities": worker.active_capabilities(config), "hardware": config.get("hardware", {}),
            "invoke_version": config.get("invoke_version", "")})
    finally:
        password = ""
        if signed_in:
            try:
                post("/logout", {})
            except Exception:
                pass
        cookies.clear()
