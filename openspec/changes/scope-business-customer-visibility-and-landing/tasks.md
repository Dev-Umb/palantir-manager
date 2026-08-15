## 1. Requirement review

- [x] 1.1 Review and approve the business customer allow/deny matrix, contact inheritance, retained roles, option ordering, and login fallback.
- [x] 1.2 Run strict OpenSpec validation before implementation.

## 2. Customer and contact scope

- [x] 2.1 Add reusable business customer scope derived from owned projects and creator-owned unlinked customers.
- [x] 2.2 Make customer contacts inherit the visible customer scope.
- [x] 2.3 Enforce record scope on generic routes, exports, relation validation, and embedded project customer/contact routes.
- [x] 2.4 Preserve administrator, finance, and tender behavior.

## 3. Project customer options

- [x] 3.1 Order project customer options with globally unlinked customers first and newest records first inside each group.
- [x] 3.2 Preserve selected customers and scoped search results.

## 4. Business login landing

- [x] 4.1 Redirect business-only login fallback to the business project table.
- [x] 4.2 Preserve intended URLs, dashboard access, registration behavior, and other role fallbacks.

## 5. Verification

- [x] 5.1 Add PHPUnit Feature allow/deny coverage for lists, detail, export, relation options, embedded customer routes, contacts, and retained roles.
- [x] 5.2 Add PHPUnit Feature coverage for option priority, selected-value preservation, search, and login redirect precedence.
- [x] 5.3 Run Pint, focused tests, strict OpenSpec validation, production build, and the full quality gate.
- [ ] 5.4 If deployment is separately authorized, back up affected code, deploy without data writes, and perform read-only online verification.
