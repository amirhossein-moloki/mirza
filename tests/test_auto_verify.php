<?php
/**
 * Test suite for Auto Verify Payment Processing and Invoice Versioning
 */

ini_set('error_log', '/dev/null');

// Global setup
global $pdo, $api_token, $APIKEY;
$api_token = "TEST_API_TOKEN";
$APIKEY = "123456:TEST_BOT_TOKEN";

// Create in-memory SQLite PDO instance
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

require_once __DIR__ . '/../text.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../functions.php';

class AutoVerifyTest
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->initDatabase();
    }

    private function initDatabase(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user (
            id VARCHAR(500) PRIMARY KEY,
            ref_code CHAR(32) NULL,
            limit_usertest INT NULL,
            roll_Status INT NULL,
            Processing_value TEXT NULL,
            Processing_value_one VARCHAR(1000) NULL,
            Processing_value_tow VARCHAR(1000) NULL,
            Processing_value_four VARCHAR(1000) NULL,
            step VARCHAR(1000) NULL,
            description_blocking TEXT NULL,
            number VARCHAR(2000) NULL,
            Balance INT DEFAULT 0,
            User_Status VARCHAR(500) NULL,
            pagenumber INT NULL,
            message_count VARCHAR(100) NULL,
            last_message_time VARCHAR(100) NULL,
            affiliatescount VARCHAR(100) NULL,
            affiliates VARCHAR(100) NULL,
            verify VARCHAR(50) NULL,
            username VARCHAR(1000) NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS Payment_report (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_user VARCHAR(200),
            id_order VARCHAR(500),
            id_invoice VARCHAR(200) NULL,
            time VARCHAR(200),
            price VARCHAR(400),
            dec_not_confirmed TEXT,
            Payment_Method VARCHAR(400),
            payment_Status VARCHAR(2000),
            invoice VARCHAR(300),
            card_last_four VARCHAR(10),
            receipt_file_id VARCHAR(1000)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS payment_auto_verify_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref_num VARCHAR(100) UNIQUE NOT NULL,
            amount INT NOT NULL,
            source_card VARCHAR(100) NOT NULL,
            request_data TEXT NOT NULL,
            status VARCHAR(100) NOT NULL,
            created_at VARCHAR(100) NOT NULL,
            selected_order_id VARCHAR(100) NULL,
            user_id VARCHAR(100) NULL,
            expected_amount INT NULL,
            amount_matched INT DEFAULT 0,
            purchase_approved INT DEFAULT 0,
            topup_created INT DEFAULT 0,
            credited_amount INT DEFAULT 0,
            audit_note TEXT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS setting (
            Bot_Status VARCHAR(200) NULL,
            Channel_Report VARCHAR(600) NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin (
            id_admin VARCHAR(200) PRIMARY KEY
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoice (
            id_invoice VARCHAR(200) PRIMARY KEY,
            id_order VARCHAR(500) NULL,
            version INT DEFAULT 1,
            discount_amount VARCHAR(200) DEFAULT '0',
            discount_code VARCHAR(1000) NULL,
            id_user VARCHAR(200) NULL,
            username VARCHAR(200) NULL,
            Service_location VARCHAR(200) NULL,
            time_sell VARCHAR(200) NULL,
            name_product VARCHAR(200) NULL,
            price_product VARCHAR(200) NULL,
            Volume VARCHAR(200) NULL,
            Service_time VARCHAR(200) NULL,
            user_info TEXT NULL,
            Status VARCHAR(200) NULL
        )");
    }

    public function resetData(): void
    {
        $this->pdo->exec("DELETE FROM user");
        $this->pdo->exec("DELETE FROM Payment_report");
        $this->pdo->exec("DELETE FROM payment_auto_verify_logs");
        $this->pdo->exec("DELETE FROM setting");
        $this->pdo->exec("DELETE FROM admin");
        $this->pdo->exec("DELETE FROM invoice");
    }

    public function assert($condition, string $message): void
    {
        if (!$condition) {
            echo "❌ FAIL: $message\n";
            exit(1);
        }
        echo "✅ PASS: $message\n";
    }

    public function testInvoiceVersioning(): void
    {
        echo "\n--- Running testInvoiceVersioning ---\n";
        $this->resetData();

        // 1. Initial purchase creates Invoice Version 1
        $inv1 = createInvoiceVersion(
            $this->pdo,
            'user1',
            'order500',
            'user_ac_1',
            'panel1',
            'Service A',
            100,
            10,
            30,
            'active'
        );

        $this->assert($inv1['version'] === 1, "Invoice Version 1 generated with version = 1");
        $this->assert($inv1['Status'] === 'active', "Invoice Version 1 status is active");
        $this->assert((int)$inv1['price_product'] === 100, "Invoice Version 1 price is 100");

        // 2. Apply discount creating Invoice Version 2
        $inv2 = createInvoiceVersion(
            $this->pdo,
            'user1',
            'order500',
            'user_ac_1',
            'panel1',
            'Service A',
            80,
            10,
            30,
            'active',
            20,
            'DISCOUNT20'
        );

        $this->assert($inv2['version'] === 2, "Invoice Version 2 generated with version = 2");
        $this->assert($inv2['Status'] === 'active', "Invoice Version 2 status is active");
        $this->assert((int)$inv2['price_product'] === 80, "Invoice Version 2 price is 80");
        $this->assert((int)$inv2['discount_amount'] === 20, "Invoice Version 2 discount amount is 20");

        // 3. Verify Version 1 is now superseded
        $stmt = $this->pdo->prepare("SELECT Status FROM invoice WHERE id_invoice = ?");
        $stmt->execute([$inv1['id_invoice']]);
        $status_v1 = $stmt->fetchColumn();
        $this->assert($status_v1 === 'superseded', "Invoice Version 1 status updated to superseded");

        // 4. Verify Version 1 row remains unchanged in DB
        $stmt = $this->pdo->prepare("SELECT price_product FROM invoice WHERE id_invoice = ?");
        $stmt->execute([$inv1['id_invoice']]);
        $price_v1 = (int)$stmt->fetchColumn();
        $this->assert($price_v1 === 100, "Invoice Version 1 amount preserved as 100 in database");

        // 5. Verify active invoice for order500 returns Version 2
        $active_inv = getActiveInvoiceForOrder($this->pdo, 'order500');
        $this->assert($active_inv['id_invoice'] === $inv2['id_invoice'], "getActiveInvoiceForOrder returns Version 2");
    }

    public function testPaymentWithDiscount(): void
    {
        echo "\n--- Running testPaymentWithDiscount ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");

        // Original Invoice 100
        $inv1 = createInvoiceVersion($this->pdo, 'user1', 'order501', 'user_ac_1', 'panel1', 'Service B', 100, 10, 30, 'active');

        // Discount applied -> Version 2 (80)
        $inv2 = createInvoiceVersion($this->pdo, 'user1', 'order501', 'user_ac_1', 'panel1', 'Service B', 80, 10, 30, 'active', 20, 'DIS20');

        // Payment record created pointing to active Version 2
        $this->pdo->exec("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES ('user1', 'order501', '{$inv2['id_invoice']}', '80', 'auto_card_to_card', 'unpaid', '4444')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 80,
            'source_card_last_four' => '4444',
            'ref_num' => 'REF_DISCOUNT_PAY_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res['http_code'] === 200, "HTTP code 200 for discounted payment");

        // Verify Payment_report updated to paid
        $stmt = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'order501'");
        $this->assert($stmt->fetchColumn() === 'paid', "Payment_report status updated to paid");

        // Verify Invoice Version 2 status updated to paid
        $stmt = $this->pdo->prepare("SELECT Status FROM invoice WHERE id_invoice = ?");
        $stmt->execute([$inv2['id_invoice']]);
        $this->assert($stmt->fetchColumn() === 'paid', "Invoice Version 2 status updated to paid");

        // Verify Version 1 remains superseded
        $stmt = $this->pdo->prepare("SELECT Status FROM invoice WHERE id_invoice = ?");
        $stmt->execute([$inv1['id_invoice']]);
        $this->assert($stmt->fetchColumn() === 'superseded', "Invoice Version 1 remains superseded");
    }

    public function testPaymentBeforeDiscount(): void
    {
        echo "\n--- Running testPaymentBeforeDiscount ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");

        // Original Invoice Version 1 (100)
        $inv1 = createInvoiceVersion($this->pdo, 'user1', 'order502', 'user_ac_1', 'panel1', 'Service C', 100, 10, 30, 'active');

        // Payment made on Version 1
        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (10, 'user1', 'order502', '{$inv1['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '1111')");

        // Later discount applied -> Version 2 (80)
        $inv2 = createInvoiceVersion($this->pdo, 'user1', 'order502', 'user_ac_1', 'panel1', 'Service C', 80, 10, 30, 'active', 20, 'DISC20');

        // Verify old payment remains attached to Invoice Version 1
        $stmt = $this->pdo->query("SELECT id_invoice, price FROM Payment_report WHERE id = 10");
        $pay_row = $stmt->fetch();

        $this->assert($pay_row['id_invoice'] === $inv1['id_invoice'], "Old payment remains attached to Version 1 ID");
        $this->assert((int)$pay_row['price'] === 100, "Old payment amount remains 100");
    }

    public function testExactMatch(): void
    {
        echo "\n--- Running testExactMatch ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");
        $inv = createInvoiceVersion($this->pdo, 'user1', 'ord101', 'user1_ac', 'panel1', 'Service A', 100, 10, 30, 'active');

        $this->pdo->exec("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, invoice, card_last_four)
                          VALUES ('user1', 'ord101', '{$inv['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '0|0', '1234')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 100,
            'source_card_last_four' => '1234',
            'ref_num' => 'REF_EXACT_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);

        $this->assert($res['http_code'] === 200, "HTTP code 200 for exact match");

        // Verify purchase updated to paid
        $stmt = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ord101'");
        $status = $stmt->fetchColumn();
        $this->assert($status === 'paid', "Purchase status is paid");

        // Verify log entry
        $stmt = $this->pdo->query("SELECT * FROM payment_auto_verify_logs WHERE ref_num = 'REF_EXACT_1'");
        $log = $stmt->fetch();
        $this->assert($log !== false, "Log entry created");
        $this->assert($log['status'] === 'success', "Log status is success");
        $this->assert((int)$log['amount_matched'] === 1, "Amount matched is 1");
        $this->assert((int)$log['purchase_approved'] === 1, "Purchase approved is 1");
    }

    public function testLowerAmount(): void
    {
        echo "\n--- Running testLowerAmount ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");
        $inv = createInvoiceVersion($this->pdo, 'user1', 'ord102', 'user1_ac', 'panel1', 'Service A', 100, 10, 30, 'active');

        $this->pdo->exec("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, invoice, card_last_four)
                          VALUES ('user1', 'ord102', '{$inv['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '0|0', '1234')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 80,
            'source_card_last_four' => '1234',
            'ref_num' => 'REF_LOWER_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);

        $this->assert($res['http_code'] === 200, "HTTP code 200 for lower amount");

        // Verify purchase NOT approved
        $stmt = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ord102'");
        $status = $stmt->fetchColumn();
        $this->assert($status === 'amount_mismatch', "Purchase status is amount_mismatch");

        // Verify FULL actual payment amount credited (+80)
        $stmt = $this->pdo->query("SELECT Balance FROM user WHERE id = 'user1'");
        $balance = (int)$stmt->fetchColumn();
        $this->assert($balance === 80, "User balance credited with FULL actual payment (80)");

        // Verify log entry
        $stmt = $this->pdo->query("SELECT * FROM payment_auto_verify_logs WHERE ref_num = 'REF_LOWER_1'");
        $log = $stmt->fetch();
        $this->assert($log['status'] === 'amount_mismatch', "Log status is amount_mismatch");
        $this->assert((int)$log['credited_amount'] === 80, "Log credited_amount is 80");
        $this->assert((int)$log['topup_created'] === 1, "Topup created is 1");
    }

    public function testHigherAmount(): void
    {
        echo "\n--- Running testHigherAmount ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 10)");
        $inv = createInvoiceVersion($this->pdo, 'user1', 'ord103', 'user1_ac', 'panel1', 'Service A', 100, 10, 30, 'active');

        $this->pdo->exec("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, invoice, card_last_four)
                          VALUES ('user1', 'ord103', '{$inv['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '0|0', '1234')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 120,
            'source_card_last_four' => '1234',
            'ref_num' => 'REF_HIGHER_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);

        $this->assert($res['http_code'] === 200, "HTTP code 200 for higher amount");

        // Verify purchase NOT approved
        $stmt = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ord103'");
        $status = $stmt->fetchColumn();
        $this->assert($status === 'amount_mismatch', "Purchase status is amount_mismatch");

        // Verify FULL actual payment amount credited (10 + 120 = 130)
        $stmt = $this->pdo->query("SELECT Balance FROM user WHERE id = 'user1'");
        $balance = (int)$stmt->fetchColumn();
        $this->assert($balance === 130, "User balance credited with FULL actual payment (+120 -> 130)");

        // Verify log entry
        $stmt = $this->pdo->query("SELECT * FROM payment_auto_verify_logs WHERE ref_num = 'REF_HIGHER_1'");
        $log = $stmt->fetch();
        $this->assert($log['status'] === 'amount_mismatch', "Log status is amount_mismatch");
        $this->assert((int)$log['credited_amount'] === 120, "Log credited_amount is 120");
    }

    public function testSameCardTwoAccountsDifferentAmounts(): void
    {
        echo "\n--- Running testSameCardTwoAccountsDifferentAmounts ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('userA', 0)");
        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('userB', 0)");

        // Account A: Active Invoice 100
        $invA = createInvoiceVersion($this->pdo, 'userA', 'ordA', 'userA_ac', 'panel1', 'Service A', 100, 10, 30, 'active');
        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (101, 'userA', 'ordA', '{$invA['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '8888')");

        // Account B: Active Invoice 80
        $invB = createInvoiceVersion($this->pdo, 'userB', 'ordB', 'userB_ac', 'panel1', 'Service B', 80, 10, 30, 'active');
        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (102, 'userB', 'ordB', '{$invB['id_invoice']}', '80', 'auto_card_to_card', 'unpaid', '8888')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 80,
            'source_card_last_four' => '8888',
            'ref_num' => 'REF_DIFF_AMT_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res['http_code'] === 200, "HTTP code 200");

        // Account B matched because active invoice amount is 80
        $statusB = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ordB'")->fetchColumn();
        $this->assert($statusB === 'paid', "Account B (ordB) status updated to paid");

        // Account A remains unpaid
        $statusA = $this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ordA'")->fetchColumn();
        $this->assert($statusA === 'unpaid', "Account A (ordA) status remains unpaid");
    }

    public function testSameCardMultipleAccounts(): void
    {
        echo "\n--- Running testSameCardMultipleAccounts ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('userA', 0)");
        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('userB', 0)");
        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('userC', 0)");

        $invA = createInvoiceVersion($this->pdo, 'userA', 'ordA', 'userA_ac', 'panel1', 'Service A', 100, 10, 30, 'active');
        $invB = createInvoiceVersion($this->pdo, 'userB', 'ordB', 'userB_ac', 'panel1', 'Service B', 100, 10, 30, 'active');
        $invC = createInvoiceVersion($this->pdo, 'userC', 'ordC', 'userC_ac', 'panel1', 'Service C', 100, 10, 30, 'active');

        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (10, 'userA', 'ordA', '{$invA['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '9999')");
        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (11, 'userB', 'ordB', '{$invB['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '9999')");
        $this->pdo->exec("INSERT INTO Payment_report (id, id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES (12, 'userC', 'ordC', '{$invC['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '9999')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 100,
            'source_card_last_four' => '9999',
            'ref_num' => 'REF_3USERS_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res['http_code'] === 200, "HTTP code 200");

        // ordA assigned as earliest
        $this->assert($this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ordA'")->fetchColumn() === 'paid', "ordA paid");
        $this->assert($this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ordB'")->fetchColumn() === 'unpaid', "ordB remains unpaid");
        $this->assert($this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ordC'")->fetchColumn() === 'unpaid', "ordC remains unpaid");
    }

    public function testDuplicateCallback(): void
    {
        echo "\n--- Running testDuplicateCallback ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");
        $inv = createInvoiceVersion($this->pdo, 'user1', 'ordDUP', 'user1_ac', 'panel1', 'Service A', 100, 10, 30, 'active');

        $this->pdo->exec("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four)
                          VALUES ('user1', 'ordDUP', '{$inv['id_invoice']}', '100', 'auto_card_to_card', 'unpaid', '1234')");

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 100,
            'source_card_last_four' => '1234',
            'ref_num' => 'REF_DUP_123',
            'timestamp' => time()
        ];

        // First attempt
        $res1 = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res1['http_code'] === 200, "First callback succeeds");

        // Second duplicate attempt
        $res2 = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res2['http_code'] === 400, "Second callback rejected as duplicate (HTTP 400)");
        $this->assert($res2['response']['message'] === 'Duplicate transaction', "Duplicate transaction error message");

        // Ensure only one log entry exists
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM payment_auto_verify_logs WHERE ref_num = 'REF_DUP_123'")->fetchColumn();
        $this->assert($count === 1, "Only 1 log entry created for duplicate callbacks");
    }

    public function testConcurrentPurchaseCreationOrder(): void
    {
        echo "\n--- Running testConcurrentPurchaseCreationOrder ---\n";
        $this->resetData();

        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user1', 0)");
        $this->pdo->exec("INSERT INTO user (id, Balance) VALUES ('user2', 0)");

        $inv1 = createInvoiceVersion($this->pdo, 'user1', 'P1', 'user1_ac', 'panel1', 'Service A', 500, 10, 30, 'active');
        $inv2 = createInvoiceVersion($this->pdo, 'user2', 'P2', 'user2_ac', 'panel1', 'Service B', 500, 10, 30, 'active');

        // Insert with auto increment IDs preserving persisted insertion order
        $stmt1 = $this->pdo->prepare("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four) VALUES ('user1', 'P1', '{$inv1['id_invoice']}', '500', 'auto_card_to_card', 'unpaid', '7777')");
        $stmt1->execute();
        $id1 = $this->pdo->lastInsertId();

        $stmt2 = $this->pdo->prepare("INSERT INTO Payment_report (id_user, id_order, id_invoice, price, Payment_Method, payment_Status, card_last_four) VALUES ('user2', 'P2', '{$inv2['id_invoice']}', '500', 'auto_card_to_card', 'unpaid', '7777')");
        $stmt2->execute();
        $id2 = $this->pdo->lastInsertId();

        $this->assert((int)$id1 < (int)$id2, "Persisted creation order (id) is strictly increasing ($id1 < $id2)");

        // Process payment
        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 500,
            'source_card_last_four' => '7777',
            'ref_num' => 'REF_CONC_1',
            'timestamp' => time()
        ];
        $res = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res['http_code'] === 200, "Payment processed");

        $this->assert($this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'P1'")->fetchColumn() === 'paid', "P1 (earliest ID) paid");
        $this->assert($this->pdo->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'P2'")->fetchColumn() === 'unpaid', "P2 remains unpaid");
    }

    public function testNoMatchingRequest(): void
    {
        echo "\n--- Running testNoMatchingRequest ---\n";
        $this->resetData();

        $payload = [
            'api_token' => 'TEST_API_TOKEN',
            'amount' => 200,
            'source_card_last_four' => '0000',
            'ref_num' => 'REF_NOMATCH_1',
            'timestamp' => time()
        ];

        $res = processAutoVerifyPayment($this->pdo, $payload);
        $this->assert($res['http_code'] === 404, "HTTP 404 for unmatched payment");

        // Verify log created with unmatched status
        $stmt = $this->pdo->query("SELECT * FROM payment_auto_verify_logs WHERE ref_num = 'REF_NOMATCH_1'");
        $log = $stmt->fetch();
        $this->assert($log !== false, "Log entry exists");
        $this->assert($log['status'] === 'unmatched', "Log status is unmatched");
        $this->assert(empty($log['user_id']), "No user assigned");
    }

    public function runAll(): void
    {
        $this->testInvoiceVersioning();
        $this->testPaymentWithDiscount();
        $this->testPaymentBeforeDiscount();
        $this->testSameCardTwoAccountsDifferentAmounts();
        $this->testExactMatch();
        $this->testLowerAmount();
        $this->testHigherAmount();
        $this->testSameCardMultipleAccounts();
        $this->testDuplicateCallback();
        $this->testConcurrentPurchaseCreationOrder();
        $this->testNoMatchingRequest();

        echo "\n🎉 ALL TESTS PASSED SUCCESSFULLY!\n";
    }
}

$test = new AutoVerifyTest($pdo);
$test->runAll();
