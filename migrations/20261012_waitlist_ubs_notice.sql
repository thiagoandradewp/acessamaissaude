ALTER TABLE public.lista_espera
    ADD COLUMN IF NOT EXISTS aviso_enviado_em TIMESTAMP NULL;

-- Agendamentos anteriores ao aviso manual não devem aparecer como pendentes para a UBS.
UPDATE public.lista_espera
   SET aviso_enviado_em = criado_em
 WHERE status <> 'pendente'
   AND aviso_enviado_em IS NULL;
