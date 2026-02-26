from fastapi import FastAPI

app = FastAPI(title="Haman AI Gateway")

@app.get("/health")
def health():
    return {"status": "ok"}
