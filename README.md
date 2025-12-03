# Belyfted Payment BAAS(Banking As a Service) Pre-Confirmation System - Laravel 12

> **A comprehensive fraud prevention and risk assessment system for payment processing**

This implementation provides real-time payment risk evaluation with pre-confirmation checks, maker-checker workflows, and comprehensive audit trails.


---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Architecture](#architecture)
- [Database Schema](#database-schema)
- [API Endpoints](#api-endpoints)
- [Risk Triggers](#risk-triggers)
- [Decision Logic](#decision-logic)
- [Form Schemas](#form-schemas)
- [Usage Examples](#usage-examples)
- [Testing](#testing)
- [Security Considerations](#security-considerations)

---

## Overview

The Belyfted Pre-Confirmation Check system evaluates payments before execution to detect and prevent:

- **Authorized Push Payment (APP) fraud**
- **Invoice redirection scams**
- **Investment/crypto scams**
- **Romance scams and impersonation**
- **Money mule activity**
- **Unusual payment patterns**

The system provides a flexible, API-first approach that can be integrated into web, mobile, or API-based payment flows.

---

## Features

### ✅ Core Capabilities

- **9 Risk Trigger Evaluations** - Comprehensive fraud detection
- **4 Decision Outcomes** - Allow, Step-up, Block, Maker-Checker
- **5 Pre-built Form Schemas** - Consumer, Business, International, Investment, Repeat
- **Document Management** - Invoice/contract upload and storage
- **Maker-Checker Workflow** - Dual approval for high-risk payments
- **Audit Trail** - Complete payment decision history
- **Webhook Support** - Real-time event notifications
- **RESTful API** - Easy integration with belyfted web and mobile

### 🎯 Use Cases

- **Consumer Banking** - Protect retail customers from APP fraud
- **Business Banking** - Invoice fraud detection, payroll verification
- **International Transfers** - Compliance and purpose code validation
- **High-Value Transactions** - Dual authorization workflows
- **Bulk Payments** - CSV/API batch processing with row-level validation

---

## Architecture

### Service Layer Architecture

```
┌─────────────────────────────────────────────────────┐
│                  API Layer (HTTP)                    │
│            RiskPreconfirmController                  │
└──────────────────┬───────────────────────────────────┘
                   │
┌──────────────────▼───────────────────────────────────┐
│               Service Layer                          │
├──────────────────────────────────────────────────────┤
│  PreconfirmCheckService                              │
│  ├─► DecisionEngineService                          │
│  ├─► RiskTriggerService                             │
│  └─► FormConfigService                              │
└──────────────────┬───────────────────────────────────┘
                   │
┌──────────────────▼───────────────────────────────────┐
│               Data Layer                             │
├──────────────────────────────────────────────────────┤
│  PreconfirmCheck Model                               │
│  PaymentApproval Model                               │
│  PaymentDocument Model                               │
└──────────────────────────────────────────────────────┘
```

### Component Breakdown

| Component | Responsibility |
|-----------|---------------|
| **RiskTriggerService** | Evaluates payment against 9 risk indicators |
| **DecisionEngineService** | Applies business rules and returns decision |
| **PreconfirmCheckService** | Orchestrates checks, stores results, emits events |
| **FormConfigService** | Provides JSON form definitions for UI rendering |

---

## Database Schema

### Tables Overview

#### `preconfirm_checks`
Stores all payment risk assessments and decisions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `payment_id` | string | Unique payment identifier |
| `user_id` | string | User making the payment |
| `risk_triggers` | json | Array of triggered risk flags |
| `answers` | json | User responses from forms |
| `cop_result` | string | Confirmation of Payee status |
| `decision` | enum | allow/step_up/block/require_maker_checker |
| `required_forms` | json | Additional forms needed |
| `required_actions` | json | Actions user must complete |
| `messages` | json | User-facing messages |
| `reviewer` | json | Approval chain metadata |
| `audit` | json | IP, user agent, timestamp |

#### `payment_approvals`
Tracks maker-checker approval workflow.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `payment_id` | string | References payment |
| `user_id` | string | Approver user ID |
| `role` | enum | maker/checker |
| `status` | enum | pending/approved/rejected |
| `notes` | text | Optional notes |
| `approved_at` | timestamp | Approval timestamp |

#### `payment_documents`
Stores uploaded invoices, contracts, and supporting documents.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `payment_id` | string | References payment |
| `type` | enum | invoice/contract/po/screenshot |
| `file_path` | string | Storage path |
| `original_name` | string | Original filename |
| `file_size` | integer | File size in bytes |

---

## API Endpoints

### Base URL
```
https://belyfted.com/api/v2/risk/preconfirm
```

All endpoints require `Authorization: Bearer {token}` header.

---

### 1. Evaluate Payment Decision

**Endpoint:** `POST /decision`

Evaluates a payment against risk triggers and returns the required next steps.

#### Request Body

```json
{
  "paymentId": "pay_9f3c1",
  "userId": "usr_123",
  "amount": {
    "currency": "GBP",
    "value": 18500
  },
  "destination": {
    "country": "GB",
    "sortCode": "04-00-04",
    "accountNumber": "12345678"
  },
  "payee": {
    "id": "ben_567",
    "isNew": true,
    "bankFingerprintChanged": true
  },
  "cop": "no_match",
  "anomaly_score": 0.86,
  "context": {
    "isBulk": false,
    "channel": "mobile",
    "org": {
      "high_value_threshold": 10000
    }
  },
  "answers": {
    "payee_relationship": "new",
    "payment_purpose": "goods_services",
    "details_source": "message_email_sms",
    "expect_goods_services": true,
    "pressure_or_secrecy": false,
    "investment_features": false
  },
  "attachments": []
}
```

#### Response

```json
{
  "decision": "STEP_UP",
  "requiredForms": ["precheck_business_v1", "intl_extension_v1"],
  "requiredActions": [
    "oob_verification_via_known_phone",
    "upload_invoice_pdf"
  ],
  "messages": [
    "Bank details appear changed. Please verify via a known phone number and upload the invoice."
  ],
  "approvals": []
}
```

#### Response Codes

| Code | Description |
|------|-------------|
| `200` | Decision evaluated successfully |
| `400` | Invalid request payload |
| `401` | Unauthorized - invalid/missing token |
| `422` | Validation failed |

---

### 2. Get Form Schema

**Endpoint:** `GET /forms/{formId}`

Retrieves JSON schema for dynamic form rendering.

#### Available Forms

- `precheck_consumer_v1` - Consumer base form
- `precheck_business_v1` - Business base form
- `intl_extension_v1` - International transfer details
- `investment_block_v1` - Investment risk assessment
- `repeat_check_v1` - Repeat payment verification

#### Example Request

```bash
GET /api/risk/preconfirm/forms/precheck_consumer_v1
Authorization: Bearer {token}
```

#### Response

```json
{
  "id": "precheck_consumer_v1",
  "version": "1.0.0",
  "title": "A quick check before we send this payment",
  "description": "This payment looks a bit unusual for your account...",
  "fields": [
    {
      "id": "payee_relationship",
      "label": "Who are you paying?",
      "type": "single_select",
      "required": true,
      "options": [
        {"value": "new", "label": "A new payee"},
        {"value": "existing", "label": "An existing payee"},
        {"value": "self", "label": "My own account"}
      ]
    }
    // ... more fields
  ]
}
```

---

### 3. Upload Supporting Document

**Endpoint:** `POST /documents/{paymentId}`

Uploads invoice, contract, or other supporting documentation.

#### Request (multipart/form-data)

```bash
POST /api/risk/preconfirm/documents/pay_9f3c1
Authorization: Bearer {token}
Content-Type: multipart/form-data

file: [binary]
type: invoice
```

#### Parameters

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| `file` | file | Yes | PDF, JPG, JPEG, PNG; max 10MB |
| `type` | string | Yes | `invoice`, `contract`, `po`, `screenshot` |

#### Response

```json
{
  "message": "Document uploaded successfully"
}
```

---

## Risk Triggers

The system evaluates 9 distinct risk triggers:

### 1. **New Payee** (`new_payee`)
- **Trigger:** `payee.isNew === true`
- **Risk:** First-time payments are high-risk for APP fraud
- **Action:** Request additional verification

### 2. **High Value** (`value_high`)
- **Trigger:** `amount.value > org.high_value_threshold`
- **Default Threshold:** £10,000
- **Action:** Require maker-checker approval

### 3. **Pattern Change** (`pattern_change`)
- **Trigger:** `anomaly_score >= 0.8`
- **Risk:** Unusual payment behavior
- **Action:** Request explanation and supporting docs

### 4. **Investment Keywords** (`investment_keywords`)
- **Trigger:** Detection of: crypto, bot, guaranteed, MT4, broker, trading
- **Risk:** Investment scams
- **Action:** Show FCA warning and require attestation

### 5. **Invoice Change** (`invoice_change`)
- **Trigger:** `payee.bankFingerprintChanged === true`
- **Risk:** Invoice redirection fraud
- **Action:** Require out-of-band verification + document upload

### 6. **International** (`international`)
- **Trigger:** `destination.country !== 'GB'`
- **Risk:** Sanctions, compliance violations
- **Action:** Collect purpose code and dual-use declaration

### 7. **Romance/Pressure** (`romance_pressure`)
- **Trigger:** User reports secrecy, urgency, or screen sharing
- **Risk:** Romance scams, impersonation
- **Action:** **HARD BLOCK** + fraud escalation

### 8. **Crypto/Mule** (`crypto_mule`)
- **Trigger:** Crypto exchange, moving funds for others, expected returns
- **Risk:** Money mule activity
- **Action:** **HARD BLOCK** + compliance review

### 9. **CoP No Match** (`cop_no_match`)
- **Trigger:** `cop === 'no_match'`
- **Risk:** Wrong account or typo
- **Action:** Display warning + require confirmation

---

## Decision Logic

### Decision Flow Diagram

```
┌─────────────────────┐
│  Payment Submitted  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Evaluate Triggers  │
└──────────┬──────────┘
           │
           ▼
    ┌──────────────┐
    │ Hard Blocks? │◄────── Romance/Pressure, Crypto/Mule
    └──────┬───────┘
           │ No
           ▼
    ┌──────────────┐
    │ Step-Up Req? │◄────── CoP, Invoice Change, International
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │ Maker-Checker│◄────── High Value, Bulk Payment
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │    ALLOW     │
    └──────────────┘
```

### Decision Outcomes

| Decision | Code | Description | User Experience |
|----------|------|-------------|-----------------|
| **Allow** | `allow` | Payment proceeds immediately | Success message, payment confirmed |
| **Step Up** | `step_up` | Additional verification required | Show additional forms/actions |
| **Block** | `block` | Payment cannot proceed | Error message, contact support |
| **Maker-Checker** | `require_maker_checker` | Dual approval needed | Pending approval notification |

---

## Form Schemas

### Consumer Form (`precheck_consumer_v1`)

**Purpose:** Pre-confirmation checks for retail banking customers

**Fields:**
1. Who are you paying? (new/existing/self)
2. Payment purpose (gift, goods, investment, etc.)
3. How did you get bank details? (invoice, email, memory)
4. Expect goods/services? (boolean)
5. Pressure or secrecy? (boolean)
6. Investment/crypto related? (boolean)
7. Declaration attestation (checkbox)

**When to show:** Any flagged consumer payment

---

### Business Form (`precheck_business_v1`)

**Purpose:** Supplier payment verification for business accounts

**Fields:**
1. Payee type (supplier, payroll, tax, refund, etc.)
2. Invoice metadata (number, date, amount)
3. Bank details changed? (boolean)
4. Out-of-band verification method
5. Source of funds
6. Maker-checker attestation

**When to show:** Business payments, especially suppliers/vendors

---

### International Extension (`intl_extension_v1`)

**Purpose:** Compliance data for cross-border transfers

**Fields:**
1. Destination country (ISO code)
2. Payment scheme (SEPA, SWIFT, ACH, local)
3. Purpose code (required for AE, IN, CN, BR, TR, MX, ZA)
4. Goods/services description
5. Dual-use/export-controlled items declaration

**When to show:** Any payment where `destination.country !== 'GB'`

---

### Investment Risk Block (`investment_block_v1`)

**Purpose:** Scam prevention for investment-related payments

**Fields:**
1. Investment type (shares, crypto, CFD, managed account)
2. Promised returns (<10%, 10-50%, >50%, "guaranteed")
3. Provider country
4. FCA Register/Warning List attestation

**When to show:** When investment keywords detected or user indicates investment purpose

---

### Repeat Check (`repeat_check_v1`)

**Purpose:** Quick verification for recurring payments

**Fields:**
1. Purpose unchanged? (boolean)
2. Bank details unchanged? (boolean)
3. Attestation checkbox

**When to show:** User repeating a previous payment to the same payee

---

## Usage Examples

### Example 1: Simple Domestic Payment (Allow)

```php
use App\Services\PreconfirmCheckService;
use App\DTOs\DecisionRequest;

$service = app(PreconfirmCheckService::class);

$request = new DecisionRequest(
    paymentId: 'pay_001',
    userId: 'usr_123',
    amount: ['currency' => 'GBP', 'value' => 250],
    destination: [
        'country' => 'GB',
        'sortCode' => '20-00-00',
        'accountNumber' => '12345678'
    ],
    payee: ['id' => 'ben_001', 'isNew' => false],
    cop: 'match',
    context: ['channel' => 'mobile']
);

$decision = $service->processCheck($request);
// Result: decision = "allow"
```

---

### Example 2: High-Value New Payee (Step-Up)

```php
$request = new DecisionRequest(
    paymentId: 'pay_002',
    userId: 'usr_456',
    amount: ['currency' => 'GBP', 'value' => 15000],
    destination: [
        'country' => 'GB',
        'sortCode' => '04-00-04',
        'accountNumber' => '87654321'
    ],
    payee: ['id' => 'ben_002', 'isNew' => true],
    cop: 'no_match',
    anomalyScore: 0.85,
    context: ['channel' => 'web', 'org' => ['high_value_threshold' => 10000]]
);

$decision = $service->processCheck($request);

// Result:
// {
//   "decision": "step_up",
//   "requiredActions": [
//     "oob_verification_via_known_phone",
//     "request_supporting_docs"
//   ],
//   "messages": [
//     "CoP failed. Verify payee via known phone.",
//     "This payment is unusual for your account..."
//   ]
// }
```

---

### Example 3: International Payment (Step-Up)

```php
$request = new DecisionRequest(
    paymentId: 'pay_003',
    userId: 'usr_789',
    amount: ['currency' => 'GBP', 'value' => 5000],
    destination: [
        'country' => 'FR',
        'iban' => 'FR1420041010050500013M02606'
    ],
    payee: ['id' => 'ben_003', 'isNew' => false],
    context: ['channel' => 'mobile']
);

$decision = $service->processCheck($request);

// Result:
// {
//   "decision": "step_up",
//   "requiredForms": ["intl_extension_v1"],
//   "messages": [
//     "International payment requires additional information."
//   ]
// }
```

---

### Example 4: Fraud Detected (Block)

```php
$request = new DecisionRequest(
    paymentId: 'pay_004',
    userId: 'usr_999',
    amount: ['currency' => 'GBP', 'value' => 8000],
    destination: [
        'country' => 'GB',
        'sortCode' => '12-34-56',
        'accountNumber' => '11223344'
    ],
    payee: ['id' => 'ben_004', 'isNew' => true],
    context: ['channel' => 'web'],
    answers: [
        'pressure_or_secrecy' => true,
        'details_source' => 'message_email_sms'
    ]
);

$decision = $service->processCheck($request);

// Result:
// {
//   "decision": "block",
//   "messages": [
//     "Reported coercion/pressure detected. Payment blocked and escalated to fraud team."
//   ]
// }
```

---

### Example 5: Business Maker-Checker (Require Approval)

```php
$request = new DecisionRequest(
    paymentId: 'pay_005',
    userId: 'usr_business_1',
    amount: ['currency' => 'GBP', 'value' => 45000],
    destination: [
        'country' => 'GB',
        'sortCode' => '60-00-00',
        'accountNumber' => '99887766'
    ],
    payee: ['id' => 'ben_supplier_1', 'isNew' => false],
    context: [
        'channel' => 'api',
        'isBulk' => false,
        'org' => ['high_value_threshold' => 10000]
    ],
    answers: [
        'payee_type' => 'supplier',
        'invoice_number' => 'INV-2025-001'
    ]
);

$decision = $service->processCheck($request);

// Result:
// {
//   "decision": "require_maker_checker",
//   "approvals": [
//     {"role": "checker", "required": true}
//   ],
//   "messages": [
//     "This payment requires approval from a second authorized person."
//   ]
// }
```

---

## Testing

### Unit Tests

Create tests in `tests/Unit/Services/`:

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\RiskTriggerService;
use App\DTOs\DecisionRequest;

class RiskTriggerServiceTest extends TestCase
{
    public function test_detects_new_payee()
    {
        $service = new RiskTriggerService();
        
        $request = new DecisionRequest(
            paymentId: 'test_001',
            userId: 'usr_001',
            amount: ['currency' => 'GBP', 'value' => 100],
            destination: ['country' => 'GB'],
            payee: ['id' => 'ben_001', 'isNew' => true],
            context: []
        );
        
        $triggers = $service->evaluateTriggers($request);
        
        $this->assertTrue($triggers['new_payee']);
    }
    
    public function test_detects_high_value()
    {
        $service = new RiskTriggerService();
        
        $request = new DecisionRequest(
            paymentId: 'test_002',
            userId: 'usr_002',
            amount: ['currency' => 'GBP', 'value' => 15000],
            destination: ['country' => 'GB'],
            payee: ['id' => 'ben_002', 'isNew' => false],
            context: ['org' => ['high_value_threshold' => 10000]]
        );
        
        $triggers = $service->evaluateTriggers($request);
        
        $this->assertTrue($triggers['value_high']);
    }
}
```

### Feature Tests

Create tests in `tests/Feature/`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;

class PreconfirmApiTest extends TestCase
{
    public function test_decision_endpoint_returns_allow()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/risk/preconfirm/decision', [
                'paymentId' => 'test_pay_001',
                'userId' => $user->id,
                'amount' => ['currency' => 'GBP', 'value' => 100],
                'destination' => [
                    'country' => 'GB',
                    'sortCode' => '20-00-00',
                    'accountNumber' => '12345678'
                ],
                'payee' => ['id' => 'ben_001', 'isNew' => false],
                'cop' => 'match',
                'context' => ['channel' => 'mobile']
            ]);
        
        $response->assertStatus(200)
            ->assertJson(['decision' => 'allow']);
    }
}
```

### Run Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Unit/Services/RiskTriggerServiceTest.php

# Run with coverage
php artisan test --coverage
```

---

## Security Considerations

### 1. Authentication & Authorization
- Implement rate limiting on decision endpoint


### 2. Input Validation

- **Validate all inputs** using Form Requests
- **Sanitize file uploads** - check MIME types, scan for malware
- **Prevent injection attacks** - use parameterized queries (Eloquent handles this)

### 3. Document Storage

- **Store documents outside web root**
- **Use private disk** in filesystem config
- **Generate signed URLs** for temporary access

```php
// config/filesystems.php
'disks' => [
    'private' => [
        'driver' => 'local',
        'root' => storage_path('app/private'),
        'visibility' => 'private',
    ],
],
```

### 5. Audit Logging

- **Log all decision events** with IP, user agent, timestamp
- **Immutable audit trail** - never delete decision records
- **Alert on suspicious patterns** - multiple blocks from same user
