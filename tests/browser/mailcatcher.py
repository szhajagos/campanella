"""A mail catcher for the browser tests (since 0.2.0): a minimal SMTP server.

    python3 tests/browser/mailcatcher.py <port> <folder>

Every message is saved in <folder> as a numbered .eml file (00001.eml, ...), with the
envelope's recipients in an added first header line `X-Envelope-To`. Nothing is sent
on. Development and tests only: it listens on 127.0.0.1, accepts everything and
does no authentication.
"""

import os
import socketserver
import sys
import threading

PORT = int(sys.argv[1])
FOLDER = sys.argv[2]
os.makedirs(FOLDER, exist_ok=True)
LOCK = threading.Lock()


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(line):
            self.wfile.write((line + "\r\n").encode())

        reply("220 campanella-mailcatcher")
        recipients, lines, in_data = [], [], False
        for raw in self.rfile:
            line = raw.decode("utf-8", "replace").rstrip("\r\n")
            if in_data:
                if line == ".":
                    self.save(recipients, lines)
                    recipients, lines, in_data = [], [], False
                    reply("250 OK")
                else:
                    lines.append(line[1:] if line.startswith("..") else line)
                continue
            command = line[:4].upper()
            if command in ("EHLO", "HELO"):
                reply("250 campanella-mailcatcher")
            elif command == "RCPT":
                recipients.append(line.split(":", 1)[-1].strip().strip("<>"))
                reply("250 OK")
            elif command == "DATA":
                in_data = True
                reply("354 End with <CRLF>.<CRLF>")
            elif command == "QUIT":
                reply("221 Bye")
                return
            else:
                reply("250 OK")

    @staticmethod
    def save(recipients, lines):
        with LOCK:
            number = len([f for f in os.listdir(FOLDER) if f.endswith(".eml")]) + 1
            path = os.path.join(FOLDER, "%05d.eml" % number)
            with open(path + ".part", "w", encoding="utf-8", newline="\r\n") as file:
                file.write("X-Envelope-To: " + ", ".join(recipients) + "\n")
                file.write("\n".join(lines) + "\n")
            os.replace(path + ".part", path)


socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer.daemon_threads = True
with socketserver.ThreadingTCPServer(("127.0.0.1", PORT), Handler) as server:
    server.serve_forever()
