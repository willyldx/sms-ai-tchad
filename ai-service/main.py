import os
import logging
import asyncio
import httpx
from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel
from openai import AsyncOpenAI
from dotenv import load_dotenv

load_dotenv()

# ── Configuration ───────────────────────────────────────────────────────
NVIDIA_API_KEY = os.getenv("NVIDIA_API_KEY")
if not NVIDIA_API_KEY:
    raise ValueError("NVIDIA_API_KEY environment variable is missing.")

# Create a custom httpx client to avoid proxy-related issues in some environments
http_client = httpx.AsyncClient(
    proxies=None, # Explicitly disable proxies if not needed
    timeout=httpx.Timeout(30.0)
)

# NVIDIA NIM uses OpenAI-compatible API
client = AsyncOpenAI(
    base_url=os.getenv("NVIDIA_BASE_URL", "https://integrate.api.nvidia.com/v1"),
    api_key=NVIDIA_API_KEY,
    http_client=http_client
)

logger = logging.getLogger("ai_service")
logging.basicConfig(
    level=os.getenv("AI_LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)

SYSTEM_INSTRUCTION = (
    "Tu es un assistant par SMS au Tchad. "
    "Tes réponses doivent faire moins de 150 caractères. "
    "Sois direct, factuel, sans aucune formule de politesse. "
    "Réponds en français."
)

AI_MODEL_NAME = os.getenv("AI_MODEL_NAME", "meta/llama-3.1-405b-instruct")
MAX_SMS_RESPONSE_LENGTH = int(os.getenv("MAX_SMS_RESPONSE_LENGTH", "150"))
AI_INTERNAL_TOKEN = os.getenv("AI_INTERNAL_TOKEN")

# ── Application FastAPI ─────────────────────────────────────────────────
app = FastAPI(title="SMS AI Tchad — AI Service (NVIDIA)")


class QuestionRequest(BaseModel):
    question: str


class AnswerResponse(BaseModel):
    answer: str


class HealthResponse(BaseModel):
    status: str
    model: str


def truncate_smart(text: str, max_length: int) -> str:
    """Tronque au dernier espace avant la limite pour ne pas couper un mot."""
    if len(text) <= max_length:
        return text
    safe_length = max(4, max_length)
    truncated = text[: safe_length - 3]
    last_space = truncated.rfind(" ")
    if last_space > safe_length // 2:
        truncated = truncated[:last_space]
    return truncated + "..."


@app.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    return HealthResponse(status="ok", model=AI_MODEL_NAME)


@app.post("/ask", response_model=AnswerResponse)
async def ask_ai(payload: QuestionRequest, request: Request):
    """
    Endpoint principal : reçoit une question, interroge NVIDIA NIM (async)
    et retourne une réponse ultra-courte adaptée au SMS.
    """
    if not payload.question.strip():
        raise HTTPException(status_code=400, detail="La question ne peut pas être vide.")

    # Vérification du token inter-services
    if AI_INTERNAL_TOKEN:
        received_token = request.headers.get("X-AI-Internal-Token")
        if received_token != AI_INTERNAL_TOKEN:
            raise HTTPException(status_code=401, detail="Non autorise")

    try:
        # Appel asynchrone à NVIDIA NIM
        completion = await client.chat.completions.create(
            model=AI_MODEL_NAME,
            messages=[
                {"role": "system", "content": SYSTEM_INSTRUCTION},
                {"role": "user", "content": payload.question}
            ],
            max_tokens=100,
            temperature=0.5,
            top_p=1,
            stream=False
        )

        answer_text = completion.choices[0].message.content.strip()
        if not answer_text:
            raise ValueError("Réponse vide générée par le modèle")

        # Troncature intelligente (coupe au dernier espace)
        answer_text = truncate_smart(answer_text, MAX_SMS_RESPONSE_LENGTH)

        return AnswerResponse(answer=answer_text)

    except Exception:
        logger.exception("Erreur NVIDIA API")
        raise HTTPException(
            status_code=500,
            detail="Erreur lors de la génération de la réponse.",
        )


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="0.0.0.0", port=8000)
