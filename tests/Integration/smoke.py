"""Exercise the shipped worker entry point over a real RoadRunner HTTP server."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import statistics
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
RR = Path(sys.argv[1]).resolve()
ITERATIONS = 1000


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def exercise(chunk_size):
    with tempfile.TemporaryDirectory(prefix="componenta-rr-") as tmp:
        root = Path(tmp)
        (root / "bin").mkdir()
        (root / "config").mkdir()
        (root / "vendor").symlink_to(ROOT / "vendor", target_is_directory=True)
        shutil.copy(ROOT / "resources/bin/roadrunner.php", root / "bin/roadrunner.php")
        for name in ("container.php", "pipeline.php"):
            shutil.copy(ROOT / "tests/Integration" / name, root / "config" / name)
        port = free_port()
        (root / ".rr.yaml").write_text(f"""version: "3"
server:
  command: "php bin/roadrunner.php"
  relay: pipes
http:
  address: 127.0.0.1:{port}
  pool:
    num_workers: 1
    max_jobs: 0
    allocate_timeout: 10s
    destroy_timeout: 10s
logs:
  level: warn
  mode: production
""")
        log_path = root / "server.log"
        with log_path.open("wb") as log:
            server = subprocess.Popen(
                [str(RR), "serve", "-c", ".rr.yaml"], cwd=root,
                env={**os.environ, "SMOKE_CHUNK_SIZE": str(chunk_size)},
                stdout=log, stderr=log,
            )
            conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)

            def request(path, method="GET", body=None, headers=None):
                conn.request(method, path, body=body, headers=headers or {})
                response = conn.getresponse()
                return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()

            def check(path, status, body, method="GET", headers=None):
                result = request(path, method, headers=headers)
                assert (result[0], result[2]) == (status, body), (path, result[0], result[2][:200])
                return result[1]

            try:
                deadline = time.monotonic() + 20
                while True:
                    if server.poll() is not None:
                        raise RuntimeError("RoadRunner exited during startup")
                    try:
                        startup = request("/full")
                        if startup[0] == 200:
                            break
                    except (OSError, http.client.HTTPException):
                        conn.close()
                    if time.monotonic() >= deadline:
                        raise RuntimeError("RoadRunner did not become ready")
                    time.sleep(.1)

                pid = startup[1]["x-worker-pid"]
                assert startup[2] == b"abcdef"
                assert check("/full", 200, b"abcdef")["content-length"] == "6"
                assert check("/full", 200, b"", method="HEAD")["content-length"] == "6"
                assert check("/partial", 206, b"abc")["content-range"] == "bytes 10-12/20"
                check("/no-content", 204, b"")
                assert check("/not-modified", 304, b"")["etag"] == '"v1"'
                check("/output", 200, b"clean")
                check("/failure", 500, b"")

                for identity in ("alice", "bob"):
                    status, headers, body = request("/state", headers={"X-Identity": identity})
                    assert status == 200 and headers["x-worker-pid"] == pid
                    data = json.loads(body)
                    assert data["previous"] is None and data["identity"] == identity and data["server"] == identity

                payload = b"--smoke\r\nContent-Disposition: form-data; name=\"document\"; filename=\"test.txt\"\r\nContent-Type: text/plain\r\n\r\nupload-content\r\n--smoke--\r\n"
                status, headers, body = request("/upload", "POST", payload, {"Content-Type": "multipart/form-data; boundary=smoke"})
                assert (status, body) == (200, b"upload-content")
                status, headers, body = request("/large")
                assert status == 200 and headers["x-worker-pid"] == pid
                assert hashlib.sha256(body).digest() == hashlib.sha256(b"0123456789abcdef" * 65536).digest()

                samples = []
                memory = []
                for i in range(ITERATIONS + 100):
                    identity = str(i)
                    start = time.perf_counter_ns()
                    status, headers, body = request("/state", headers={"X-Identity": identity})
                    elapsed = (time.perf_counter_ns() - start) / 1_000_000
                    data = json.loads(body)
                    assert status == 200 and headers["x-worker-pid"] == pid
                    assert data["previous"] is None and data["identity"] == identity and data["server"] == identity
                    if i >= 100:
                        samples.append(elapsed)
                        memory.append(data["memory"])
                assert max(memory) - min(memory) <= 8 * 1024 * 1024, memory[-10:]
                ordered = sorted(samples)
                print(f"chunk_size={chunk_size} requests={ITERATIONS} pid={pid} p50_ms={statistics.median(samples):.3f} "
                      f"p95_ms={ordered[int(.95 * len(ordered))-1]:.3f} p99_ms={ordered[int(.99 * len(ordered))-1]:.3f} "
                      f"php_allocated_memory_min={min(memory)} max={max(memory)}", flush=True)

                check("/reset-failure", 200, b"discard")
                deadline = time.monotonic() + 15
                while time.monotonic() < deadline:
                    try:
                        status, headers, body = request("/full")
                        if status == 200:
                            assert body == b"abcdef" and headers["x-worker-pid"] != pid
                            break
                    except (OSError, http.client.HTTPException):
                        conn.close()
                    time.sleep(.1)
                else:
                    raise AssertionError("RoadRunner did not replace a failed worker")
            except BaseException:
                print(log_path.read_text(errors="replace"), file=sys.stderr)
                raise
            finally:
                conn.close()
                server.terminate()
                try:
                    server.wait(timeout=15)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()


for chunk_size in (0, 8192):
    exercise(chunk_size)
