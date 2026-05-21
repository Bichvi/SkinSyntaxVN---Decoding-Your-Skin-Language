@echo off
chcp 65001 >nul
echo.
echo ============================================
echo  SkinSyntaxVN - Start AI Chatbot Service
echo ============================================
echo.

cd /d "%~dp0"

echo [INFO] Dang kiem tra va tu dong cai dat cac thu vien con thieu...
python -m pip install --quiet flask flask-cors python-dotenv langchain-chroma langchain-huggingface langchain-google-genai langchain-openai sentence-transformers pydantic

echo.
echo [INFO] Dang khoi dong Flask chatbot tren port 5001...
echo [INFO] Nhan Ctrl+C de dung service
echo.

set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8

python chatbot_flask.py

pause
