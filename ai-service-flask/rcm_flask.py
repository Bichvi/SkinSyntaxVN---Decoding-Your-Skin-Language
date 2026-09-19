"""Backward-compatible launcher for the packaged recommendation service."""

from recommendation_service import config
from recommendation_service.rcm_flask import app, create_app

__all__ = ["app", "create_app"]


if __name__ == "__main__":
    app.run(host=config.HOST, port=config.PORT, debug=False, use_reloader=False)
