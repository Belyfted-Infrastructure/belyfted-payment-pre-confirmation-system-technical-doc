<?php

// ============================================================================
// DATABASE MIGRATIONS
// ============================================================================

// database/migrations/2025_01_01_000001_create_preconfirm_checks_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preconfirm_checks', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->unique();
            $table->string('user_id')->index();
            $table->json('risk_triggers');
            $table->json('answers');
            $table->string('cop_result')->nullable();
            $table->enum('decision', ['allow', 'step_up', 'block', 'require_maker_checker']);
            $table->json('required_forms')->nullable();
            $table->json('required_actions')->nullable();
            $table->json('messages')->nullable();
            $table->json('reviewer')->nullable();
            $table->json('audit')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->index();
            $table->string('user_id');
            $table->enum('role', ['maker', 'checker']);
            $table->enum('status', ['pending', 'approved', 'rejected']);
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_documents', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->index();
            $table->enum('type', ['invoice', 'contract', 'po', 'screenshot']);
            $table->string('file_path');
            $table->string('original_name');
            $table->integer('file_size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_documents');
        Schema::dropIfExists('payment_approvals');
        Schema::dropIfExists('preconfirm_checks');
    }
};

// ============================================================================
// MODELS
// ============================================================================

// app/Models/PreconfirmCheck.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreconfirmCheck extends Model
{
    protected $fillable = [
        'payment_id',
        'user_id',
        'risk_triggers',
        'answers',
        'cop_result',
        'decision',
        'required_forms',
        'required_actions',
        'messages',
        'reviewer',
        'audit',
    ];

    protected $casts = [
        'risk_triggers' => 'array',
        'answers' => 'array',
        'required_forms' => 'array',
        'required_actions' => 'array',
        'messages' => 'array',
        'reviewer' => 'array',
        'audit' => 'array',
    ];

    public function approvals(): HasMany
    {
        return $this->hasMany(PaymentApproval::class, 'payment_id', 'payment_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PaymentDocument::class, 'payment_id', 'payment_id');
    }
}

// app/Models/PaymentApproval.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentApproval extends Model
{
    protected $fillable = [
        'payment_id',
        'user_id',
        'role',
        'status',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];
}

// app/Models/PaymentDocument.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentDocument extends Model
{
    protected $fillable = [
        'payment_id',
        'type',
        'file_path',
        'original_name',
        'file_size',
    ];
}

// ============================================================================
// DATA TRANSFER OBJECTS (DTOs)
// ============================================================================

// app/DTOs/DecisionRequest.php
namespace App\DTOs;

class DecisionRequest
{
    public function __construct(
        public string $paymentId,
        public string $userId,
        public array $amount,
        public array $destination,
        public array $payee,
        public ?string $cop = null,
        public ?float $anomalyScore = null,
        public array $context = [],
        public array $answers = [],
        public array $attachments = []
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            paymentId: $data['paymentId'],
            userId: $data['userId'],
            amount: $data['amount'],
            destination: $data['destination'],
            payee: $data['payee'],
            cop: $data['cop'] ?? null,
            anomalyScore: $data['anomaly_score'] ?? null,
            context: $data['context'] ?? [],
            answers: $data['answers'] ?? [],
            attachments: $data['attachments'] ?? []
        );
    }
}

// app/DTOs/DecisionResponse.php
namespace App\DTOs;

class DecisionResponse
{
    public function __construct(
        public string $decision,
        public array $requiredForms = [],
        public array $requiredActions = [],
        public array $messages = [],
        public array $approvals = []
    ) {}

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'requiredForms' => $this->requiredForms,
            'requiredActions' => $this->requiredActions,
            'messages' => $this->messages,
            'approvals' => $this->approvals,
        ];
    }
}

// ============================================================================
// SERVICES
// ============================================================================

// app/Services/RiskTriggerService.php
namespace App\Services;

use App\DTOs\DecisionRequest;

class RiskTriggerService
{
    public function evaluateTriggers(DecisionRequest $request): array
    {
        $highValueThreshold = $request->context['org']['high_value_threshold'] ?? 10000;
        
        return [
            'new_payee' => $request->payee['isNew'] ?? false,
            'value_high' => $request->amount['value'] > $highValueThreshold,
            'pattern_change' => ($request->anomalyScore ?? 0) >= 0.8,
            'investment_keywords' => $this->hasInvestmentKeywords($request->answers),
            'invoice_change' => $request->payee['bankFingerprintChanged'] ?? false,
            'international' => $this->isInternational($request->destination),
            'romance_pressure' => $this->hasRomancePressure($request->answers),
            'crypto_mule' => $this->hasCryptoMuleIndicators($request->answers),
            'cop_no_match' => $request->cop === 'no_match',
        ];
    }

    private function hasInvestmentKeywords(array $answers): bool
    {
        $keywords = ['crypto', 'bot', 'guaranteed', 'MT4', 'broker', 'trading'];
        
        foreach ($answers as $value) {
            if (is_string($value)) {
                foreach ($keywords as $keyword) {
                    if (stripos($value, $keyword) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return $answers['investment_features'] ?? false;
    }

    private function isInternational(array $destination): bool
    {
        return isset($destination['country']) && $destination['country'] !== 'GB';
    }

    private function hasRomancePressure(array $answers): bool
    {
        return ($answers['pressure_or_secrecy'] ?? false) ||
               ($answers['keep_secret'] ?? false) ||
               ($answers['rushed'] ?? false) ||
               ($answers['screen_share'] ?? false);
    }

    private function hasCryptoMuleIndicators(array $answers): bool
    {
        return ($answers['crypto_exchange'] ?? false) ||
               ($answers['moving_for_others'] ?? false) ||
               ($answers['funds_returned'] ?? false);
    }
}

// app/Services/DecisionEngineService.php
namespace App\Services;

use App\DTOs\{DecisionRequest, DecisionResponse};

class DecisionEngineService
{
    public function __construct(
        private RiskTriggerService $triggerService
    ) {}

    public function evaluatePayment(DecisionRequest $request): DecisionResponse
    {
        $triggers = $this->triggerService->evaluateTriggers($request);
        
        // Hard block scenarios
        if ($triggers['romance_pressure']) {
            return new DecisionResponse(
                decision: 'block',
                messages: ['Reported coercion/pressure detected. Payment blocked and escalated to fraud team.']
            );
        }

        if ($triggers['crypto_mule']) {
            return new DecisionResponse(
                decision: 'block',
                messages: ['Potential money mule activity detected. Payment blocked. Please contact support.']
            );
        }

        // Build step-up requirements
        $forms = [];
        $actions = [];
        $messages = [];
        $approvals = [];

        // International payments
        if ($triggers['international']) {
            $forms[] = 'intl_extension_v1';
            $messages[] = 'International payment requires additional information.';
        }

        // Investment-related
        if ($triggers['investment_keywords']) {
            $forms[] = 'investment_block_v1';
            $messages[] = 'Investment-related payment requires FCA verification.';
        }

        // CoP no match
        if ($triggers['cop_no_match'] && $triggers['new_payee']) {
            $actions[] = 'oob_verification_via_known_phone';
            $messages[] = 'Confirmation of Payee failed. Please verify payee details via a known phone number.';
        }

        // Invoice/bank details change
        if ($triggers['invoice_change']) {
            $actions[] = 'upload_invoice_pdf';
            $actions[] = 'oob_verification_via_known_phone';
            $messages[] = 'Bank details have changed. Please upload invoice and verify via a known number.';
        }

        // High value or pattern change
        if ($triggers['value_high'] || $triggers['pattern_change']) {
            $actions[] = 'request_supporting_docs';
            $messages[] = 'This payment is unusual for your account. Additional verification required.';
        }

        // Maker-checker requirement
        if ($triggers['value_high'] || $request->context['isBulk'] ?? false) {
            $approvals[] = ['role' => 'checker', 'required' => true];
            $messages[] = 'This payment requires approval from a second authorized person.';
            
            return new DecisionResponse(
                decision: 'require_maker_checker',
                requiredForms: $forms,
                requiredActions: $actions,
                messages: $messages,
                approvals: $approvals
            );
        }

        // Determine final decision
        $decision = (!empty($forms) || !empty($actions)) ? 'step_up' : 'allow';

        return new DecisionResponse(
            decision: $decision,
            requiredForms: $forms,
            requiredActions: $actions,
            messages: $messages,
            approvals: $approvals
        );
    }
}

// app/Services/PreconfirmCheckService.php
namespace App\Services;

use App\Models\PreconfirmCheck;
use App\DTOs\{DecisionRequest, DecisionResponse};
use App\Events\PreconfirmCompleted;
use Illuminate\Support\Facades\DB;

class PreconfirmCheckService
{
    public function __construct(
        private DecisionEngineService $decisionEngine,
        private RiskTriggerService $triggerService
    ) {}

    public function processCheck(DecisionRequest $request): DecisionResponse
    {
        return DB::transaction(function () use ($request) {
            $triggers = $this->triggerService->evaluateTriggers($request);
            $decision = $this->decisionEngine->evaluatePayment($request);

            $check = PreconfirmCheck::create([
                'payment_id' => $request->paymentId,
                'user_id' => $request->userId,
                'risk_triggers' => array_keys(array_filter($triggers)),
                'answers' => $request->answers,
                'cop_result' => $request->cop,
                'decision' => $decision->decision,
                'required_forms' => $decision->requiredForms,
                'required_actions' => $decision->requiredActions,
                'messages' => $decision->messages,
                'audit' => [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            event(new PreconfirmCompleted($check));

            return $decision;
        });
    }

    public function storeDocument(string $paymentId, $file, string $type): void
    {
        $path = $file->store('payment-documents', 'private');

        PaymentDocument::create([
            'payment_id' => $paymentId,
            'type' => $type,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
        ]);
    }
}

// ============================================================================
// FORM CONFIGURATION SERVICE
// ============================================================================

// app/Services/FormConfigService.php
namespace App\Services;

class FormConfigService
{
    public function getForm(string $formId): array
    {
        return match ($formId) {
            'precheck_consumer_v1' => $this->getConsumerForm(),
            'precheck_business_v1' => $this->getBusinessForm(),
            'intl_extension_v1' => $this->getInternationalForm(),
            'investment_block_v1' => $this->getInvestmentForm(),
            'repeat_check_v1' => $this->getRepeatCheckForm(),
            default => throw new \InvalidArgumentException("Unknown form: $formId"),
        };
    }

    private function getConsumerForm(): array
    {
        return [
            'id' => 'precheck_consumer_v1',
            'version' => '1.0.0',
            'title' => 'A quick check before we send this payment',
            'description' => 'This payment looks a bit unusual for your account. These checks help protect you from fraud and mistakes.',
            'fields' => [
                [
                    'id' => 'payee_relationship',
                    'label' => 'Who are you paying?',
                    'type' => 'single_select',
                    'required' => true,
                    'options' => [
                        ['value' => 'new', 'label' => 'A new payee'],
                        ['value' => 'existing', 'label' => 'An existing payee'],
                        ['value' => 'self', 'label' => 'My own account'],
                    ],
                ],
                [
                    'id' => 'payment_purpose',
                    'label' => "What's the purpose of this payment?",
                    'type' => 'single_select',
                    'required' => true,
                    'options' => [
                        ['value' => 'gift_family', 'label' => 'Gift or family & friends'],
                        ['value' => 'goods_services', 'label' => 'Paying for goods or services'],
                        ['value' => 'investment', 'label' => 'Investment or trading'],
                        ['value' => 'property', 'label' => 'Property/rent/deposit'],
                        ['value' => 'loan', 'label' => 'Loan to someone'],
                        ['value' => 'charity', 'label' => 'Donation/charity'],
                        ['value' => 'other', 'label' => 'Other'],
                    ],
                ],
                [
                    'id' => 'details_source',
                    'label' => "How did you get the payee's bank details?",
                    'type' => 'single_select',
                    'required' => true,
                    'options' => [
                        ['value' => 'official_invoice_portal', 'label' => 'Invoice or official website/portal'],
                        ['value' => 'direct_in_person', 'label' => 'Directly from the person (in person/phone)'],
                        ['value' => 'message_email_sms', 'label' => 'From email/SMS/WhatsApp/social media'],
                        ['value' => 'friend_third_party', 'label' => 'From a friend or third party'],
                        ['value' => 'memory_manual', 'label' => 'Typed from memory'],
                    ],
                ],
                [
                    'id' => 'expect_goods_services',
                    'label' => 'Do you expect to receive goods/services for this payment?',
                    'type' => 'boolean',
                    'required' => true,
                ],
                [
                    'id' => 'pressure_or_secrecy',
                    'label' => 'Has anyone asked you to keep this payment secret or do it urgently?',
                    'type' => 'boolean',
                    'required' => true,
                ],
                [
                    'id' => 'investment_features',
                    'label' => "Is this related to crypto, trading bots, or 'guaranteed returns'?",
                    'type' => 'boolean',
                    'required' => true,
                ],
                [
                    'id' => 'attestation',
                    'label' => 'Declaration',
                    'type' => 'checkbox',
                    'required' => true,
                    'text' => "I confirm this payment is my decision. I understand bank transfers may be irreversible if I've been scammed.",
                ],
            ],
        ];
    }

    private function getBusinessForm(): array
    {
        return [
            'id' => 'precheck_business_v1',
            'version' => '1.0.0',
            'title' => 'Quick supplier checks',
            'description' => 'We run a brief check for fraud and payment errors.',
            'fields' => [
                [
                    'id' => 'payee_type',
                    'label' => 'Payee type',
                    'type' => 'single_select',
                    'required' => true,
                    'options' => [
                        ['value' => 'supplier', 'label' => 'Supplier / Vendor'],
                        ['value' => 'payroll', 'label' => 'Employee / Payroll'],
                        ['value' => 'tax_duty', 'label' => 'Tax / Duties / HMRC'],
                        ['value' => 'refund', 'label' => 'Customer refund'],
                        ['value' => 'intercompany', 'label' => 'Intercompany'],
                        ['value' => 'professional_fees', 'label' => 'Legal/Accounting/Professional'],
                        ['value' => 'other', 'label' => 'Other'],
                    ],
                ],
                // Additional fields would follow the document structure
            ],
        ];
    }

    private function getInternationalForm(): array
    {
        return [
            'id' => 'intl_extension_v1',
            'title' => 'International transfer details',
            'fields' => [
                [
                    'id' => 'destination_country',
                    'label' => 'Destination country',
                    'type' => 'country',
                    'required' => true,
                ],
                [
                    'id' => 'purpose_code',
                    'label' => 'Purpose code',
                    'type' => 'single_select',
                    'required_if' => ['destination_country_in' => ['AE', 'IN', 'CN', 'BR', 'TR', 'MX', 'ZA']],
                ],
            ],
        ];
    }

    private function getInvestmentForm(): array
    {
        return [
            'id' => 'investment_block_v1',
            'title' => 'Investment risk check',
            'fields' => [
                [
                    'id' => 'investment_type',
                    'label' => 'Type of investment',
                    'type' => 'single_select',
                    'options' => [
                        ['value' => 'listed', 'label' => 'Listed shares/ISA/SIPP'],
                        ['value' => 'crypto', 'label' => 'Crypto/digital assets'],
                        ['value' => 'cfd_fx', 'label' => 'CFD/FX platform'],
                        ['value' => 'managed_account', 'label' => "'Managed account' or copy-trading"],
                        ['value' => 'other', 'label' => 'Other'],
                    ],
                ],
            ],
        ];
    }

    private function getRepeatCheckForm(): array
    {
        return [
            'id' => 'repeat_check_v1',
            'title' => 'Confirm repeat payment',
            'fields' => [
                [
                    'id' => 'purpose_unchanged',
                    'label' => 'Is the purpose unchanged since last time?',
                    'type' => 'boolean',
                    'required' => true,
                ],
            ],
        ];
    }
}

// ============================================================================
// CONTROLLERS
// ============================================================================

// app/Http/Controllers/Api/RiskPreconfirmController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{PreconfirmCheckService, FormConfigService};
use App\DTOs\DecisionRequest;
use App\Http\Requests\PreconfirmDecisionRequest;
use Illuminate\Http\JsonResponse;

class RiskPreconfirmController extends Controller
{
    public function __construct(
        private PreconfirmCheckService $checkService,
        private FormConfigService $formService
    ) {}

    public function decision(PreconfirmDecisionRequest $request): JsonResponse
    {
        $dto = DecisionRequest::fromRequest($request->validated());
        $response = $this->checkService->processCheck($dto);

        return response()->json($response->toArray());
    }

    public function getForm(string $formId): JsonResponse
    {
        try {
            $form = $this->formService->getForm($formId);
            return response()->json($form);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Form not found'], 404);
        }
    }

    public function uploadDocument(string $paymentId): JsonResponse
    {
        request()->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'type' => 'required|in:invoice,contract,po,screenshot',
        ]);

        $this->checkService->storeDocument(
            $paymentId,
            request()->file('file'),
            request()->input('type')
        );

        return response()->json(['message' => 'Document uploaded successfully']);
    }
}

// ============================================================================
// REQUESTS
// ============================================================================

// app/Http/Requests/PreconfirmDecisionRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreconfirmDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paymentId' => 'required|string',
            'userId' => 'required|string',
            'amount' => 'required|array',
            'amount.currency' => 'required|string|size:3',
            'amount.value' => 'required|numeric|min:0',
            'destination' => 'required|array',
            'destination.country' => 'required|string|size:2',
            'payee' => 'required|array',
            'payee.id' => 'required|string',
            'payee.isNew' => 'required|boolean',
            'cop' => 'nullable|in:match,close_match,no_match,not_supported',
            'anomaly_score' => 'nullable|numeric|min:0|max:1',
            'context' => 'required|array',
            'answers' => 'nullable|array',
            'attachments' => 'nullable|array',
        ];
    }
}

// ============================================================================
// EVENTS
// ============================================================================

// app/Events/PreconfirmCompleted.php
namespace App\Events;

use App\Models\PreconfirmCheck;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PreconfirmCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public PreconfirmCheck $check) {}
}

// ============================================================================
// ROUTES
// ============================================================================

// routes/api.php
use App\Http\Controllers\Api\RiskPreconfirmController;

Route::middleware('auth:sanctum')->prefix('risk/preconfirm')->group(function () {
    Route::post('/decision', [RiskPreconfirmController::class, 'decision']);
    Route::get('/forms/{formId}', [RiskPreconfirmController::class, 'getForm']);
    Route::post('/documents/{paymentId}', [RiskPreconfirmController::class, 'uploadDocument']);
});

// ============================================================================
// SERVICE PROVIDER (Register Services)
// ============================================================================

// app/Providers/AppServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\{
    RiskTriggerService,
    DecisionEngineService,
    PreconfirmCheckService,
    FormConfigService
};

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RiskTriggerService::class);
        $this->app->singleton(DecisionEngineService::class);
        $this->app->singleton(PreconfirmCheckService::class);
        $this->app->singleton(FormConfigService::class);
    }
}