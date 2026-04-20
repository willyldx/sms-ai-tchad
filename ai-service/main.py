import os
import logging
from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel
import google.generativeai as genai
from dotenv import load_dotenv

# Chargement des variables d'environnement (Clé API)
load_dotenv()

# Configuration de l'API Gemini
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")
if not GEMINI_API_KEY:
    raise ValueError("La variable d'environnement GEMINI_API_KEY est manquante.")

genai.configure(api_key=GEMINI_API_KEY)

logger = logging.getLogger("ai_service")
logging.basicConfig(
    level=os.getenv("AI_LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)

# Configuration de l'instruction système pour un comportement strict
SYSTEM_INSTRUCTION = (
    "Tu es un assistant par SMS. Tes réponses doivent faire moins de 150 caractères. "
    "Sois direct, factuel, sans aucune formule de politesse."
)

AI_MODEL_NAME = os.getenv("AI_MODEL_NAME", "gemini-1.5-flash")
MAX_SMS_RESPONSE_LENGTH = int(os.getenv("MAX_SMS_RESPONSE_LENGTH", "150"))
AI_INTERNAL_TOKEN = os.getenv("AI_INTERNAL_TOKEN")

# Initialisation du modèle avec l'instruction système
model = genai.GenerativeModel(
    model_name=AI_MODEL_NAME,
    system_instruction=SYSTEM_INSTRUCTION
)

app = FastAPI(title="Le Cerveau de Poche - AI Service")

# Modèles de données Pydantic
class QuestionRequest(BaseModel):
    question: str

class AnswerResponse(BaseModel):
    answer: str

class HealthResponse(BaseModel):
    status: str
    model: str


@app.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    return HealthResponse(status="ok", model=AI_MODEL_NAME)

@app.post("/ask", response_model=AnswerResponse)
async def ask_ai(payload: QuestionRequest, request: Request):
    """
    Endpoint principal : Reçoit une question, interroge Gemini 
    et retourne une réponse ultra-courte.
    """
    if not payload.question.strip():
        raise HTTPException(status_code=400, detail="La question ne peut pas être vide.")

    if AI_INTERNAL_TOKEN:
        received_token = request.headers.get("X-AI-Internal-Token")
        if received_token != AI_INTERNAL_TOKEN:
            raise HTTPException(status_code=401, detail="Non autorise")

    try:
        # Génération de la réponse
        response = model.generate_content(payload.question)
        
        # Extraction du texte (nettoyage des espaces superflus)
        answer_text = response.text.strip()
        if not answer_text:
            raise ValueError("Réponse vide générée par le modèle")

        # Sécurité supplémentaire : On tronque si l'IA dépasse la consigne
        if len(answer_text) > MAX_SMS_RESPONSE_LENGTH:
            safe_length = max(4, MAX_SMS_RESPONSE_LENGTH)
            answer_text = answer_text[: safe_length - 3] + "..."

        return AnswerResponse(answer=answer_text)

    except Exception:
        logger.exception("Erreur Gemini")
        raise HTTPException(status_code=500, detail="Erreur lors de la génération de la réponse.")

if __name__ == "__main__":
    import uvicorn
    # Lancement du serveur sur le port 8000
    uvicorn.run(app, host="0.0.0.0", port=8000)
