#!/usr/bin/env python3
"""
Leichtgewichtiger WebSocket- & noVNC-Reverse-Proxy für WHMCS Custom Proxmox LXC.
Validiert das von WHMCS per HMAC-SHA256 signierte Konsolen-Token, injiziert
das Proxmox PVEAuthCookie + VNC-Ticket serverseitig und tunnelt den WebSocket-Stream
direkt an den Proxmox vncwebsocket-Endpunkt.
"""

import asyncio
import base64
import hashlib
import hmac
import json
import os
import ssl
import time
from urllib.parse import quote
import aiohttp
from aiohttp import web

# Festes Secret für Testzwecke
HMAC_SECRET = "123456789 testing only".encode("utf-8")
LISTEN_HOST = os.environ.get("LISTEN_HOST", "0.0.0.0")
LISTEN_PORT = int(os.environ.get("LISTEN_PORT", "8765"))


def verify_token(token: str) -> dict | None:
    if not token or "." not in token:
        return None
    encoded_payload, signature = token.split(".", 1)
    expected_sig = hmac.new(HMAC_SECRET, encoded_payload.encode("utf-8"), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected_sig, signature):
        return None

    padding = "=" * (-len(encoded_payload) % 4)
    try:
        payload = json.loads(base64.urlsafe_b64decode(encoded_payload + padding).decode("utf-8"))
    except Exception:
        return None

    if int(payload.get("exp", 0)) < int(time.time()):
        return None

    return payload


async def handle_console_page(request: web.Request) -> web.Response:
    token = request.query.get("token", "")
    payload = verify_token(token)
    if not payload:
        return web.Response(status=403, text="Ungültiges oder abgelaufenes Konsolen-Token.")

    vmid = payload["vmid"]
    vncticket = payload["vncticket"]

    html = f"""<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>LXC Konsole - CT {vmid}</title>
  <style>
    html, body {{ margin: 0; padding: 0; width: 100%; height: 100%; background: #111; color: #eee; font-family: sans-serif; overflow: hidden; }}
    #bar {{ height: 34px; background: #1e1e1e; display: flex; align-items: center; justify-content: space-between; padding: 0 12px; font-size: 13px; border-bottom: 1px solid #333; }}
    #screen {{ width: 100%; height: calc(100% - 34px); }}
  </style>
</head>
<body>
  <div id="bar">
    <span>Container <strong>CT {vmid}</strong> (Gesicherte WebSocket-Konsole)</span>
    <span id="status">Verbinde...</span>
  </div>
  <div id="screen"></div>
  <script type="module">
    import RFB from 'https://cdn.jsdelivr.net/npm/@novnc/novnc@1.4.0/core/rfb.js';
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsUrl = `${{proto}}//${{window.location.host}}/ws?token={quote(token)}`;
    const statusEl = document.getElementById('status');
    const rfb = new RFB(document.getElementById('screen'), wsUrl, {{
      credentials: {{ password: {json.dumps(vncticket)} }}
    }});
    rfb.scaleViewport = true;
    rfb.resizeSession = true;
    rfb.addEventListener('connect', () => statusEl.textContent = 'Verbunden');
    rfb.addEventListener('disconnect', () => statusEl.textContent = 'Verbindung getrennt');
  </script>
</body>
</html>"""
    return web.Response(text=html, content_type="text/html")


async def handle_ws_proxy(request: web.Request) -> web.StreamResponse:
    token = request.query.get("token", "")
    payload = verify_token(token)
    if not payload:
        return web.Response(status=403, text="Unauthorized")

    client_ws = web.WebSocketResponse(protocols=("binary",))
    await client_ws.prepare(request)

    node = payload["node"]
    vmid = payload["vmid"]
    port = payload["port"]
    vncticket = quote(payload["vncticket"], safe="")
    pveticket = payload["pveticket"]
    serverip = payload["serverip"]

    pve_ws_url = f"wss://{serverip}:8006/api2/json/nodes/{node}/lxc/{vmid}/vncwebsocket?port={port}&vncticket={vncticket}"

    ssl_ctx = ssl.create_default_context()
    ssl_ctx.check_hostname = False
    ssl_ctx.verify_mode = ssl.CERT_NONE

    headers = {
        "Cookie": f"PVEAuthCookie={pveticket}"
    }

    try:
        async with aiohttp.ClientSession() as session:
            async with session.ws_connect(
                pve_ws_url,
                ssl=ssl_ctx,
                headers=headers,
                protocols=("binary",)
            ) as pve_ws:

                async def client_to_pve():
                    async for msg in client_ws:
                        if msg.type == aiohttp.WSMsgType.BINARY:
                            await pve_ws.send_bytes(msg.data)
                        elif msg.type == aiohttp.WSMsgType.TEXT:
                            await pve_ws.send_str(msg.data)
                        elif msg.type in (aiohttp.WSMsgType.CLOSE, aiohttp.WSMsgType.ERROR):
                            await pve_ws.close()
                            break

                async def pve_to_client():
                    async for msg in pve_ws:
                        if msg.type == aiohttp.WSMsgType.BINARY:
                            await client_ws.send_bytes(msg.data)
                        elif msg.type == aiohttp.WSMsgType.TEXT:
                            await client_ws.send_str(msg.data)
                        elif msg.type in (aiohttp.WSMsgType.CLOSE, aiohttp.WSMsgType.ERROR):
                            await client_ws.close()
                            break

                await asyncio.gather(client_to_pve(), pve_to_client())
    except Exception as exc:
        if not client_ws.closed:
            await client_ws.close(code=1011, message=str(exc).encode("utf-8")[:120])

    return client_ws


app = web.Application()
app.router.add_get("/console", handle_console_page)
app.router.add_get("/ws", handle_ws_proxy)

if __name__ == "__main__":
    web.run_app(app, host=LISTEN_HOST, port=LISTEN_PORT)
