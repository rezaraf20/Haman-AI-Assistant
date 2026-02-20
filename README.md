# HamanChatbot
Enterprise-grade multilingual AI assistant for web agencies built with RAG architecture, FastAPI, Docker, and microservices. Designed for lead generation, company promotion, and scalable AI-powered customer interaction.

FlowChart :
┌───────────────────────┐
│        User           │
└────────────┬──────────┘
             │
             ▼
┌───────────────────────┐
│  Frontend Chat Widget │
│ (JS Embed in Website) │
└────────────┬──────────┘
             │ REST API
             ▼
┌────────────────────────────┐
│        API Gateway         │
│        (FastAPI)           │
└────────────┬───────────────┘
             │
   ┌─────────┴──────────┐
   ▼                    ▼
┌──────────────┐   ┌──────────────┐
│ Conversation │   │ Lead Service │
│   Service    │   │              │
└──────┬───────┘   └──────┬───────┘
       │                    │
       ▼                    ▼
┌──────────────┐      ┌──────────────┐
│   RAG Engine │      │  Database    │
│              │      │ (Leads/CRM)  │
└──────┬───────┘      └──────────────┘
       │
       ▼
┌──────────────┐
│  Vector DB   │
│  (Qdrant)    │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   LLM API    │
│ (GPT Model)  │
└──────────────┘



