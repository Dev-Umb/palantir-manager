## MODIFIED Requirements

### Requirement: Outstanding payments enter reminders only with an approved stage and valid last payment date

The system MUST place a project into the existing payment-reminder cycle only when its overall status is `已拿到加工函` or `合同签署`, its unpaid amount is numeric and greater than zero, and its last payment date is present and valid.

#### Scenario: Processing letter stage has a due outstanding payment

- **WHEN** a project overall status is `已拿到加工函`
- **AND** its unpaid amount is greater than zero
- **AND** its valid last payment date has reached one natural month old
- **THEN** the project MUST enter the payment-reminder cycle

#### Scenario: Signed contract stage has a due outstanding payment

- **WHEN** a project overall status is `合同签署`
- **AND** its unpaid amount is greater than zero
- **AND** its valid last payment date has reached one natural month old
- **THEN** the project MUST enter the payment-reminder cycle

#### Scenario: Project is at an earlier stage

- **WHEN** a project overall status is not `已拿到加工函` or `合同签署`
- **THEN** the system MUST NOT create a payment reminder solely because a dated outstanding payment exists

#### Scenario: Outstanding amount is not positive

- **WHEN** an otherwise eligible project has a missing, non-numeric, zero or negative unpaid amount
- **THEN** the current payment reminder MUST be resolved
- **AND** no new payment-reminder occurrence MUST be sent

#### Scenario: Last payment date is unavailable

- **WHEN** an otherwise eligible project has a missing, blank or invalid last payment date
- **THEN** the project MUST NOT enter the payment-reminder cycle
- **AND** the system MUST NOT substitute project creation time, update time, processing-letter time or the legacy payment-reminder anchor

### Requirement: Outstanding payments repeat every fifteen days after the first due date

The system MUST trigger the first payment reminder one natural month after the valid last payment date and MUST repeat every 15 days after a due occurrence while all eligibility conditions remain satisfied. Recipient resolution, occurrence counting, idempotency and non-payment reminder rules MUST remain unchanged.

#### Scenario: First payment reminder preserves the natural-month delay

- **WHEN** an eligible project reaches one natural month after its last payment date
- **THEN** the existing recipients MUST receive the first occurrence

#### Scenario: Last payment date has not reached one natural month

- **WHEN** an otherwise eligible project's last payment date is less than one natural month old
- **THEN** the system MUST NOT create a payment-reminder occurrence

#### Scenario: An eligible reminder repeats

- **WHEN** an eligible project remains outstanding for 15 days after the last due occurrence
- **THEN** the existing recipients MUST receive one new occurrence
- **AND** repeated synchronization in the same cycle MUST NOT duplicate it

#### Scenario: Last payment date changes

- **WHEN** an authorized financial update changes the valid last payment date
- **THEN** the old active payment reminder MUST be resolved
- **AND** the next payment reminder MUST be due one natural month after the new last payment date

#### Scenario: Other project reminder types synchronize to Feishu

- **WHEN** the notification center creates a due bid, processing-letter or contract-signature project notification for a business owner
- **THEN** its eligibility and due date MUST remain governed by the existing notification-center rules
- **AND** the existing Feishu dispatcher MUST create a delivery for that notification recipient without changing the station notification
