## MODIFIED Requirements

### Requirement: Incomplete payments enter reminders at the approved project stages

The system MUST place a project into the existing payment-reminder cycle when its overall status is `已拿到加工函` or `合同签署` and its payment status is not `已回款`.

#### Scenario: Processing letter stage has no completed payment

- **WHEN** a project overall status is `已拿到加工函` and payment status is `未回款` or `部分回款`
- **THEN** the project MUST enter the payment-reminder cycle
- **AND** a missing processing-letter date MUST NOT by itself make the project ineligible

#### Scenario: Signed contract stage has no completed payment

- **WHEN** a project overall status is `合同签署` and payment status is `未回款` or `部分回款`
- **THEN** the project MUST enter the payment-reminder cycle

#### Scenario: Project is at an earlier stage

- **WHEN** a project overall status is not `已拿到加工函` or `合同签署`
- **THEN** the system MUST NOT create a payment reminder solely because payment is incomplete

#### Scenario: Payment is complete

- **WHEN** an otherwise eligible project has payment status `已回款`
- **THEN** the current payment reminder MUST be resolved
- **AND** no new payment-reminder occurrence MUST be sent

### Requirement: Existing reminder behavior remains intact

The system MUST preserve the current natural-month cadence, recipient resolution, occurrence counting, idempotency and non-payment reminder rules.

#### Scenario: An eligible reminder repeats

- **WHEN** an eligible project remains incomplete through the next natural-month due date
- **THEN** the existing recipients MUST receive one new occurrence
- **AND** repeated synchronization in the same cycle MUST NOT duplicate it

#### Scenario: Other project reminder types synchronize

- **WHEN** bid, processing-letter or contract-signature reminder conditions are met
- **THEN** their eligibility, due dates and recipients MUST remain governed by their pre-change rules
