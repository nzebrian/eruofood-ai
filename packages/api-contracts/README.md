# @eruofood/api-contracts

The **contract-first** source of truth for the EruoFood AI REST API.

- `openapi.yaml` — the OpenAPI 3.1 specification. Edit this **before** changing
  server or client code.
- `generated/` — machine-generated client types (git-ignored; produced in CI
  and locally). Never edit by hand.

## Workflow

```bash
npm install
npm run lint          # validate the spec
npm run generate:ts   # emit TypeScript types for the web client
npm run preview       # browse the docs locally
```

CI validates the spec and fails the build on breaking changes, keeping the
web, mobile, and API contracts in lockstep (MASTER_PLAN.md §6.1, §10.2).
