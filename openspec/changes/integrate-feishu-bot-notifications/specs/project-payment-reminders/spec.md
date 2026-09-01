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

### Requirement: Incomplete payments repeat every fifteen days after the first due date

The system MUST trigger the first payment reminder one natural month after the current reminder anchor and MUST repeat every 15 days after a due occurrence while payment remains incomplete. Recipient resolution, occurrence counting, idempotency, anchor reset behavior and non-payment reminder rules MUST remain unchanged.

#### Scenario: First payment reminder preserves the natural-month delay

- **WHEN** an eligible project reaches one natural month after its current payment reminder anchor
- **THEN** the existing recipients MUST receive the first occurrence

#### Scenario: An eligible reminder is not yet fifteen days old

- **WHEN** an eligible project remains incomplete but fewer than 15 days have elapsed since the last due occurrence
- **THEN** the system MUST NOT create another occurrence

#### Scenario: An eligible reminder repeats

- **WHEN** an eligible project remains incomplete for 15 days after the last due occurrence
- **THEN** the existing recipients MUST receive one new occurrence
- **AND** repeated synchronization in the same cycle MUST NOT duplicate it

#### Scenario: A financial update resets the reminder anchor

- **WHEN** an authorized financial update resets the payment reminder anchor
- **THEN** the next payment reminder MUST be due one natural month after the new anchor

#### Scenario: Other project reminder types synchronize

- **WHEN** bid, processing-letter or contract-signature reminder conditions are met
- **THEN** their eligibility, due dates and recipients MUST remain governed by their pre-change rules
