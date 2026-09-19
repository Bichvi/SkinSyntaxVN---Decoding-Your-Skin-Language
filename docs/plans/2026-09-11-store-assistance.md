# Support content and chat-assisted checkout

## Scope and acceptance
- One authored support catalog drives 5 topic groups, individual articles, FAQs and grounded chat replies with article links.
- Use actual email OTP/reset flows. Unconfirmed company identity, delivery promises, refund SLA and VAT arrangements remain explicitly unconfirmed.
- Purchase intent must exclude advice/comparison/negation. Find real catalog records, offer explicit selection, quote current price and quantity, then hand off to existing checkout. Never create an order in chat.
- Guest selection survives login; staff checkout restrictions remain. No cart mutation while searching/selecting. POST actions require a session token, offered product membership, expiry, quantity and current availability/price checks.
- Existing skincare advice and scroll behavior remain covered by regression checks.

## Design choices
The PHP BFF owns store policies and purchase actions; Flask continues to own skincare advice. Public website chat routes support questions before the LLM, using the same curated content as the pages. This keeps policy promises independent of model guessing and does not couple PHP to Python internals. General ambiguous questions link to the relevant topic instead of inventing an answer.

New services: SupportKnowledge, PurchaseIntent, ChatPurchaseService. New controllers: SupportController, ShopAssistantController. A shared route include is wired into all three entry points. New support view/CSS and a separate chat commerce JS/CSS module keep the existing widget integration small.

Visual direction: existing site typeface; forest #20563e, sage #e9f2ec, ink #23352c, muted #63736a, paper #ffffff. A readable topic directory and article navigation, natural product photography from the catalog; no generated imagery. Mobile collapses to one column. Purchase choices include explicit selection, quantity and checkout controls.

## Impact review before edits
Repo/worktree: SkinSyntaxVN---Decoding-Your-Skin-Language at C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language. Graph commit and HEAD: 84d4d5ed7a12f7c64e89d6b4ba71cda7eb444419; index 2026-09-10. Existing uncommitted work preserved.
- aiChatAssistant: LOW, direct callers aiChatApi and aiChatStream; three public entry points upstream.
- Entry files, footer and embedded widget: UNKNOWN graph coverage; manually confirmed Nginx SCRIPT_FILENAME, router switches, HomeController.render -> footer -> ai_chat_widget, renderMessages callers and sendMessage form/quick prompt handlers. Graph matches for same-named functions in public/assets/js are a different implementation and are not used as evidence for this widget.
- Existing payment submission/auth handlers are reused, not replaced. New checkout handoff is critical-path work and must be validated explicitly.

## Verification
PHP syntax, independent service tests with fake repository and session; live HTTP contract tests against real local catalog; existing Python consultation regression suite; browser flows via available Playwright surface. No configured PHPUnit/npm/E2E runner was found in manifests; distinguish manual browser verification from a configured suite. No OTP emails or financial transactions during tests.

## Sources / publication gaps
Official government records confirm Law 122/2025/QH15 effective 2026-07-01 and Law 91/2025/QH15 effective 2026-01-01. Do not claim compliance solely from a 2013 decree template. Operator must complete legal identity and validate operating model/required registrations before public launch. Policy text does not waive statutory consumer rights.
