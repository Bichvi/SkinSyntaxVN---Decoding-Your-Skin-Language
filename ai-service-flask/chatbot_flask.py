# -*- coding: utf-8 -*-
"""
SkinSyntaxVN — Chatbot Flask Service Entrypoint Shim
Exposes Flask app and functions from chatbot_service.chatbot_flask.
"""
import os
import sys
from pathlib import Path

_APP_DIR = str(Path(__file__).resolve().parent)
_SERVICE_DIR = str(Path(__file__).resolve().parent / "chatbot_service")

if _SERVICE_DIR not in sys.path:
    sys.path.insert(0, _SERVICE_DIR)
if _APP_DIR not in sys.path:
    sys.path.insert(0, _APP_DIR)

from chatbot_service.chatbot_flask import *
from chatbot_service.chatbot_flask import app

if __name__ == "__main__":
    port = int(os.getenv("CHATBOT_PORT", 5001))
    app.run(host="0.0.0.0", port=port, debug=False)
