# OASISCRM Production Status PDF Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a one-page Indonesian PDF explaining in plain language why the current OASISCRM changes should not be pulled directly into production yet.

**Architecture:** Prepare a print-friendly HTML document with a concise executive summary, status table, risks, and safe next steps. Convert the HTML to PDF using the installed Chromium/Edge headless print function, then inspect the generated PDF metadata and page count.

**Tech Stack:** HTML/CSS, Microsoft Edge headless PDF printing, PowerShell/Python standard tools for validation.

**Spec:** Approved one-page design in the conversation.

## Global Constraints

- Use plain, non-technical Indonesian suitable for an atasan.
- Do not include passwords, tokens, `.env` contents, or sensitive personal data.
- State clearly that the audit was read-only and no pull, build, or migration was performed.
- Preserve the verified facts: production `aa8052b`, local `88219ab`, no pending production migrations, valid database backup, and untracked production artifacts.

## Review Focus

- The explanation must distinguish “not safe to pull now” from “production is broken”; validate the wording in the final PDF.
- The PDF must fit on one page and remain readable; validate page count and rendered output.
- The backup statement must not imply that a backup alone makes deployment safe; validate the recommendation wording.
- The document must avoid exposing secrets or unnecessary infrastructure details; inspect extracted text before completion.

### Task 1: Create the executive PDF source

**Files:**
- Create: `artifacts/oasiscrm-production-status.html`

- [ ] Write one-page print CSS and Indonesian copy covering the current status, four plain-language reasons, data-safety evidence, and the recommended deployment sequence.
- [ ] Check the source for accidental credentials, tokens, `.env` values, or unnecessary personal data.

### Task 2: Render and validate the PDF

**Files:**
- Create: `artifacts/OASISCRM_Status_Kesiapan_Production.pdf`

- [ ] Render the HTML to PDF with headless Edge.
- [ ] Validate the output exists, has exactly one page, and contains the expected title and conclusion text.
- [ ] Review the rendered page for clipping and readability before delivery.
