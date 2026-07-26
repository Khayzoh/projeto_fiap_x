# ADR-004 — JWT HS256 com segredo compartilhado

- **Status:** aceito
- **Data:** 2026-07-26

## Contexto

O desafio exige que o sistema seja protegido por usuário e senha. O projeto base
não tinha autenticação alguma: qualquer visitante via e baixava todos os ZIPs.

A Fase 3 do curso já havia estabelecido JWT HS256 com segredo compartilhado
como estratégia de autenticação (ADR-002 daquele projeto), com a validação
acontecendo localmente em cada serviço.

## Decisão

Autenticação por e-mail e senha, com a senha armazenada como hash bcrypt.
O login bem-sucedido emite um JWT assinado em **HS256** com um segredo
compartilhado, válido por 120 minutos.

A biblioteca é `firebase/php-jwt`, com a emissão e a validação concentradas em
`App\Support\Jwt`. O middleware `JwtAuthenticate` protege todas as rotas de vídeo.

## Consequências

**Positivas**

- Qualquer serviço que conheça o segredo valida o token sem chamar a API — não
  há round-trip de introspecção no caminho de cada requisição.
- Mantém continuidade com a decisão da Fase 3, incluindo o formato dos claims.
- Sem estado de sessão: qualquer réplica atende qualquer requisição.

**Negativas**

- Um token não pode ser revogado antes de expirar; o TTL curto limita o dano.
- O segredo é simétrico: quem valida também consegue emitir. Em um cenário com
  muitos consumidores, RS256 (chave assimétrica) seria mais adequado.

## Cuidados aplicados

- O login responde `401` genérico tanto para e-mail inexistente quanto para senha
  errada, sem revelar quais contas existem.
- `POST /api/auth/login` tem throttle de 5 requisições por minuto.
- Um token válido de um usuário já removido é recusado — o middleware confirma
  a existência do usuário antes de liberar a requisição.
- Vídeo de outro usuário responde `404`, não `403`: não revela sequer que o
  recurso existe.
