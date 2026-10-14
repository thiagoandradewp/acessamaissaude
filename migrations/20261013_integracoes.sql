CREATE TABLE IF NOT EXISTS public.configuracoes (
    chave VARCHAR(80) PRIMARY KEY,
    valor TEXT NOT NULL,
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- As credenciais de e-mail/WhatsApp ficam aqui: somente o backend acessa.
ALTER TABLE public.configuracoes ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.configuracoes FROM PUBLIC, anon, authenticated;
GRANT ALL ON TABLE public.configuracoes TO service_role;
