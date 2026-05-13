<?php
/**
 * =============================================================================
 * FILE:    DoctorsControllerTest.php
 * PROJECT: Umbrella Corporation - Clinic Management System
 * MODULE:  Doctors
 * DATE:    2026-05-12
 *
 * DESCRIPTION:
 * Unit test script for the Doctors module. Tests are executed by calling the
 * REST API directly via cURL and verifying results against source-code logic
 * found in DoctorController.php and DoctorsController.php.
 *
 * TESTING METHODOLOGY (per Section 5.3 guidelines):
 *   Step 1 - Build test cases to achieve branch coverage (Level 2).
 *            Expected output is derived from design/spec, NOT from reading
 *            source code output blindly.
 *   Step 2 - Call the API, verify result, perform CheckDB to confirm DB state.
 *   Step 3 - Manual Rollback in finally blocks to restore DB to pre-test state.
 *
 * NOTE ON ROLLBACK STRATEGY:
 * This system uses the Pixie Query Builder which manages its own PDO connection
 * internally and does not expose it for external transaction control.
 * Therefore PDO::beginTransaction() + rollback() cannot wrap API calls.
 * Manual rollback is used instead:
 *   - After INSERT test: DELETE the created record
 *   - After UPDATE test: UPDATE back to the original value
 *   - After DELETE/Deactivate test: restore active=1 or re-INSERT the record
 * This is the standard approach when transaction-based rollback is unavailable.
 *
 * NOTE ON RESPONSE PARSING (Bug DKH_41):
 * The server has a known bug where PHPMailer prints an SMTP error string to
 * stdout BEFORE the JSON response body during doctor creation. This corrupts
 * the raw response and causes json_decode() to fail.
 * Fix applied: extractJson() strips any leading non-JSON garbage by finding
 * the first '{' character, then attempts to decode only the JSON portion.
 * This makes the parser robust against the SMTP bug while still recording it.
 *
 * API ROUTES (from routes.inc.php):
 *   POST   API/login         -> Login, get token
 *   GET    API/doctors        -> DoctorsController::getAll()
 *   POST   API/doctors        -> DoctorsController::save()
 *   GET    API/doctors/{id}   -> DoctorController::getById()
 *   PUT    API/doctors/{id}   -> DoctorController::update()
 *   DELETE API/doctors/{id}   -> DoctorController::delete()
 *
 * DATABASE: nextpost, table: tn_doctors
 * Schema: id, email, phone, password, name, description, price, role,
 *         active, avatar, create_at, update_at, speciality_id, room_id,
 *         recovery_token
 *
 * HOW TO RUN:
 *   php DoctorsControllerTest.php          -> Run all test groups
 *   php DoctorsControllerTest.php list     -> Group 1: Get all doctors
 *   php DoctorsControllerTest.php create   -> Group 2: Create doctor
 *   php DoctorsControllerTest.php detail   -> Group 3: Get doctor by ID
 *   php DoctorsControllerTest.php update   -> Group 4: Update doctor
 *   php DoctorsControllerTest.php delete   -> Group 5: Delete / deactivate
 * =============================================================================
 */

// =============================================================================
// CONFIGURATION - Adjust to match local environment
// =============================================================================
define('API_BASE_URL',       'http://localhost:8080/PTIT-Do-An-Tot-Nghiep/api');
define('ADMIN_EMAIL',        'phongkaster@gmail.com');
define('ADMIN_PASSWORD',     '123456');
define('DB_HOST',            '127.0.0.1');
define('DB_PORT',            3306);
define('DB_NAME',            'nextpost');
define('DB_USER',            'root');
define('DB_PASSWORD',        '');           // Default XAMPP: empty
define('TABLE_DOCTORS',      'tn_doctors');
define('TABLE_APPOINTMENTS', 'tn_appointments');

// =============================================================================
// CLASS Color - ANSI terminal color helpers
// =============================================================================
class Color {
    const GREEN  = "\033[32m";
    const RED    = "\033[31m";
    const YELLOW = "\033[33m";
    const BLUE   = "\033[34m";
    const CYAN   = "\033[36m";
    const BOLD   = "\033[1m";
    const RESET  = "\033[0m";
    public static function green(string $t):  string { return self::GREEN  . $t . self::RESET; }
    public static function red(string $t):    string { return self::RED    . $t . self::RESET; }
    public static function yellow(string $t): string { return self::YELLOW . $t . self::RESET; }
    public static function blue(string $t):   string { return self::BLUE   . $t . self::RESET; }
    public static function cyan(string $t):   string { return self::CYAN   . $t . self::RESET; }
    public static function bold(string $t):   string { return self::BOLD   . $t . self::RESET; }
}

// =============================================================================
// CLASS DatabaseHelper
// Used for:
//   CheckDB  - verify the database was changed correctly after an API call
//   Rollback - restore the database to its pre-test state in finally blocks
// =============================================================================
class DatabaseHelper {
    private ?PDO $pdo = null;
    public bool $connected = false;

    public function __construct() {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                           DB_HOST, DB_PORT, DB_NAME);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->connected = true;
        } catch (PDOException $e) {
            echo Color::yellow("[DB WARNING] Cannot connect: " . $e->getMessage()) . "\n";
            echo Color::yellow("[DB WARNING] CheckDB and Rollback steps will be skipped.") . "\n\n";
        }
    }

    /** Execute a SELECT query; returns array of rows */
    public function query(string $sql, array $params = []): array {
        if (!$this->connected) return [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Execute INSERT / UPDATE / DELETE */
    public function execute(string $sql, array $params = []): void {
        if (!$this->connected) return;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * CheckDB: Fetch a doctor record by ID directly from the database.
     * @param int $id Doctor ID
     * @return array|null Row array or null if not found
     */
    public function getDoctorById(int $id): ?array {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_DOCTORS . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * CheckDB: Fetch a doctor record by email address.
     * Used to retrieve the auto-generated ID after a successful create.
     * @param string $email Doctor email
     * @return array|null Row array or null if not found
     */
    public function getDoctorByEmail(string $email): ?array {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_DOCTORS . " WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * CheckDB: Find an active doctor (active=1, not admin) who has at least
     * one appointment record. Used to test the deactivate branch of delete().
     * @return int|null Doctor ID or null if none found
     */
    public function getDoctorIdWithAppointment(): ?int {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT DISTINCT d.id FROM " . TABLE_DOCTORS . " d
             INNER JOIN " . TABLE_APPOINTMENTS . " a ON a.doctor_id = d.id
             WHERE d.active = 1 AND d.role != 'admin'
             LIMIT 1"
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /**
     * CheckDB: Find an active doctor who has NO appointment records.
     * Used to test the hard-delete branch of delete().
     * @return int|null Doctor ID or null if none found
     */
    public function getDoctorIdWithoutAppointment(): ?int {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT d.id FROM " . TABLE_DOCTORS . " d
             LEFT JOIN " . TABLE_APPOINTMENTS . " a ON a.doctor_id = d.id
             WHERE d.active = 1 AND d.role != 'admin' AND a.id IS NULL
             LIMIT 1"
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /**
     * Rollback: Hard-delete a doctor record created during a test.
     * Called in finally blocks after successful INSERT tests.
     * @param int $id Doctor ID to delete
     */
    public function rollbackDeleteDoctor(int $id): void {
        if (!$this->connected) return;
        // Remove related appointments first to avoid FK constraint violations
        $this->execute(
            "DELETE FROM " . TABLE_APPOINTMENTS . " WHERE doctor_id = ?", [$id]
        );
        $this->execute(
            "DELETE FROM " . TABLE_DOCTORS . " WHERE id = ?", [$id]
        );
    }

    /**
     * Rollback: Restore a doctor's phone number to its original value.
     * Called in finally blocks after UPDATE tests that changed phone.
     * @param int    $id            Doctor ID
     * @param string $originalPhone The phone value before the test ran
     */
    public function rollbackRestorePhone(int $id, string $originalPhone): void {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_DOCTORS . " SET phone = ? WHERE id = ?",
            [$originalPhone, $id]
        );
    }

    /**
     * Rollback: Restore active=1 for a doctor that was deactivated during a test.
     * Also resets any appointments set to 'cancelled' back to 'confirmed'.
     * @param int $id Doctor ID
     */
    public function rollbackRestoreActive(int $id): void {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_DOCTORS . " SET active = 1 WHERE id = ?", [$id]
        );
        // Restore appointments that were cancelled by the deactivation logic
        $this->execute(
            "UPDATE " . TABLE_APPOINTMENTS
            . " SET status = 'confirmed' WHERE doctor_id = ? AND status = 'cancelled'",
            [$id]
        );
    }

    /**
     * Rollback: Re-insert a doctor record that was hard-deleted during a test.
     * @param array $originalData Full row returned by getDoctorById() before deletion
     */
    public function rollbackRestoreDeletedDoctor(array $originalData): void {
        if (!$this->connected) return;
        $this->execute(
            "INSERT INTO " . TABLE_DOCTORS . "
             (id, email, phone, password, name, description, price, role,
              active, avatar, create_at, update_at, speciality_id, room_id, recovery_token)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $originalData['id'],
                $originalData['email'],
                $originalData['phone'],
                $originalData['password'],
                $originalData['name'],
                $originalData['description'],
                $originalData['price'],
                $originalData['role'],
                $originalData['active'],
                $originalData['avatar'],
                $originalData['create_at'],
                $originalData['update_at'],
                $originalData['speciality_id'],
                $originalData['room_id'],
                $originalData['recovery_token'] ?? ''
            ]
        );
    }

    public function close(): void { $this->pdo = null; }
}

// =============================================================================
// CLASS HttpClient - Sends HTTP requests via cURL
// The API uses: Authorization: JWT {token} and type: Doctor headers
// =============================================================================
class HttpClient {
    private ?string $accessToken = null;

    public function setToken(string $token): void  { $this->accessToken = $token; }
    public function clearToken(): void             { $this->accessToken = null;  }

    /**
     * Execute an HTTP request.
     * @param string $method  GET | POST | PUT | DELETE
     * @param string $url     Full endpoint URL
     * @param array  $data    Request body or query parameters
     * @return array ['status' => int, 'body' => array, 'raw' => string,
     *               'smtp_error' => bool]
     *   smtp_error = true when the server prepended SMTP error text before the
     *   JSON body (Bug DKH_41). The body is still parsed from the JSON portion.
     */
    public function request(string $method, string $url, array $data = []): array {
        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: JWT ' . $this->accessToken;
            $headers[] = 'type: Doctor';
        }

        switch (strtoupper($method)) {
            case 'GET':
                if (!empty($data)) $url .= '?' . http_build_query($data);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawBody   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'status'     => 0,
                'body'       => ['result' => 0, 'msg' => 'cURL Error: ' . $curlError],
                'raw'        => '',
                'smtp_error' => false,
            ];
        }

        // --- Robust JSON extraction (handles Bug DKH_41 SMTP prefix) ---
        $smtpError = false;
        $jsonStr   = $rawBody;
        $firstBrace = strpos($rawBody, '{');
        if ($firstBrace !== false && $firstBrace > 0) {
            // There is garbage text before the JSON object
            $smtpError = true;
            $jsonStr   = substr($rawBody, $firstBrace);
        }

        $parsed = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = ['result' => 0, 'msg' => 'Invalid JSON: ' . $rawBody];
        }

        return [
            'status'     => $httpStatus,
            'body'       => $parsed,
            'raw'        => $rawBody,
            'smtp_error' => $smtpError,
        ];
    }
}

// =============================================================================
// CLASS TestRunner - Manages and executes all test cases
// =============================================================================
class TestRunner {
    private int   $totalTests  = 0;
    private int   $passedTests = 0;
    private int   $failedTests = 0;
    private array $failedList  = [];
    private array $bugList     = [];    // Track genuine bugs found in the SUT

    private HttpClient     $http;
    private DatabaseHelper $db;

    public function __construct() {
        $this->http = new HttpClient();
        $this->db   = new DatabaseHelper();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function printGroupHeader(string $groupName): void {
        echo "\n" . Color::bold(Color::blue(
            "══════════════════════════════════════════════\n" .
            " {$groupName}\n" .
            "══════════════════════════════════════════════"
        )) . "\n";
    }

    /**
     * Record the result of a single test case.
     * @param string $testId      Test Case ID (e.g. UT_DOC_001)
     * @param string $description Short description of the test objective
     * @param bool   $passed      true = PASS, false = FAIL
     * @param string $message     Additional details (CheckDB, rollback status, bug notes)
     */
    private function recordResult(
        string $testId,
        string $description,
        bool   $passed,
        string $message = ''
    ): void {
        $this->totalTests++;
        if ($passed) {
            $this->passedTests++;
            echo sprintf("  %s %s %s\n",
                Color::green('[PASS]'), Color::cyan($testId), $description);
            if ($message) echo Color::green("       ↳ {$message}") . "\n";
        } else {
            $this->failedTests++;
            echo sprintf("  %s %s %s\n",
                Color::red('[FAIL]'), Color::cyan($testId), $description);
            if ($message) echo Color::red("       ↳ {$message}") . "\n";
            $this->failedList[] = "[{$testId}] {$description}";
            if ($message) $this->failedList[] = "        {$message}";
        }
    }

    /** Record a confirmed bug in the System Under Test */
    private function recordBug(string $bugId, string $description): void {
        $this->bugList[] = "[{$bugId}] {$description}";
        echo Color::yellow("       ⚠ BUG {$bugId}: {$description}") . "\n";
    }

    private function printSummary(): void {
        echo "\n" . Color::bold("══════════════════════════════════════════════") . "\n";
        echo Color::bold(" OVERALL TEST RESULTS") . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n";
        echo sprintf("  Total tests : %d\n", $this->totalTests);
        echo sprintf("  %s        : %d\n", Color::green('PASS'), $this->passedTests);
        echo sprintf("  %s        : %d\n", Color::red('FAIL'), $this->failedTests);

        if (!empty($this->failedList)) {
            echo "\n" . Color::red(Color::bold("  FAILED TEST CASES:")) . "\n";
            foreach ($this->failedList as $line) {
                echo Color::red("  {$line}") . "\n";
            }
        }

        if (!empty($this->bugList)) {
            echo "\n" . Color::yellow(Color::bold("  BUGS CONFIRMED IN SYSTEM UNDER TEST:")) . "\n";
            foreach ($this->bugList as $line) {
                echo Color::yellow("  {$line}") . "\n";
            }
        }

        $rate = $this->totalTests > 0
            ? round($this->passedTests / $this->totalTests * 100, 1) : 0;
        echo "\n" . Color::bold(sprintf("  Pass rate: %s%%", $rate)) . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n\n";
    }

    /** Admin login to obtain a JWT token. This step is NOT counted as a test case. */
    private function doLogin(): void {
        $resp = $this->http->request('POST', API_BASE_URL . '/login', [
            'email'    => ADMIN_EMAIL,
            'password' => ADMIN_PASSWORD,
            'type'     => 'doctor',
        ]);
        if (($resp['body']['result'] ?? 0) == 1) {
            $token = $resp['body']['accessToken'];
            $this->http->setToken($token);
            echo Color::green("  ✓ Admin login successful\n");
        } else {
            echo Color::red("  ✗ Admin login FAILED - all subsequent tests will fail\n");
        }
    }


    // =========================================================================
    // GROUP 1: GET ALL DOCTORS
    // Tests DoctorsController::getAll() via GET /doctors
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  No filter             -> returns all doctors
    //   B2  search filter         -> LIKE filter on name/email/phone/description
    //   B3  search with no match  -> empty result set
    //   B4  speciality_id filter  -> WHERE speciality_id = ?
    //   B5  active=1 filter       -> WHERE active = 1
    //   B6  active=0 filter       -> Bug: $active=0 is falsy in PHP -> filter skipped
    //   B7  length/start paging   -> LIMIT ? OFFSET ?
    //   B8  No auth token         -> redirect to login (HTTP 302)
    //
    // KNOWN BUG UNDER TEST:
    //   active=0 filter is broken: `if($active)` treats 0 as falsy so
    //   the WHERE clause is never applied. All doctors are returned regardless.
    // =========================================================================
    public function runListTests(): void {
        $this->printGroupHeader("GROUP 1: GET ALL DOCTORS (GET /doctors)");

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_001
        // Objective: getAll() returns a valid list when called with no filters
        // Input  : GET /doctors (no parameters), valid admin token
        // Expected: result=1, 'data' is an array, 'quantity' is present
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_001';
        $desc   = 'Standard: GET /doctors no filter -> result=1, data array, quantity present';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, quantity={$body['quantity']}, returned count=" . count($body['data'])
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_002
        // Objective: getAll() filters by search keyword (name match)
        //            Source code uses LIKE 'keyword%' (starts-with, not contains)
        //            This means search='Phong' only matches names/emails/phones
        //            that START WITH 'Phong', not contain it.
        // Input  : GET /doctors?search=Phong
        // Expected: result=1, every returned record has name/email/phone
        //           that starts with 'Phong' (case-insensitive LIKE 'Phong%')
        // -------------------------------------------------------------------
        $testId    = 'UT_DOC_002';
        $searchKey = 'Phong';
        $desc      = "Standard: GET /doctors?search={$searchKey} -> all results match the keyword";
        $resp      = $this->http->request('GET', API_BASE_URL . '/doctors',
                         ['search' => $searchKey]);
        $body      = $resp['body'];
        $passed    = ($body['result'] ?? 0) == 1;
        $mismatch  = '';
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                // LIKE 'keyword%' means the field must start with the keyword
                $nameMatch  = stripos($item['name']  ?? '', $searchKey) === 0;
                $emailMatch = stripos($item['email'] ?? '', $searchKey) === 0;
                $phoneMatch = stripos($item['phone'] ?? '', $searchKey) === 0;
                $descMatch  = stripos($item['description'] ?? '', $searchKey) === 0;
                if (!$nameMatch && !$emailMatch && !$phoneMatch && !$descMatch) {
                    $passed   = false;
                    $mismatch = "Record name='{$item['name']}' does not start with '{$searchKey}'";
                    break;
                }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Returned " . count($body['data'] ?? []) . " records, all start with '{$searchKey}'"
                : $mismatch . " -> Bug: search filter may return unrelated records (ref DKH_30)"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_003
        // Objective: getAll() returns empty when search keyword matches nothing
        // Input  : GET /doctors?search=xyznotfound123
        // Expected: result=1, data is empty array
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_003';
        $desc   = 'Standard: GET /doctors?search=xyznotfound123 -> empty result set';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors',
                      ['search' => 'xyznotfound123']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && count($body['data'] ?? []) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, 0 records returned (correct)"
                : "Expected 0 records, got " . count($body['data'] ?? [])
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_004
        // Objective: getAll() correctly filters by speciality_id
        // Input  : GET /doctors?speciality_id=1
        // Expected: result=1, every record has speciality.id == 1
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_004';
        $desc   = 'Standard: GET /doctors?speciality_id=1 -> all results belong to speciality 1';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors',
                      ['speciality_id' => 1]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if ((int)($item['speciality']['id'] ?? 0) !== 1) {
                    $passed = false;
                    break;
                }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? count($body['data'] ?? []) . " records, all speciality.id=1"
                : "A record was returned that does not belong to speciality_id=1"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_005
        // Objective: getAll() correctly filters active=1 (only active doctors)
        // Input  : GET /doctors?active=1
        // Expected: result=1, every record has active == 1
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_005';
        $desc   = 'Standard: GET /doctors?active=1 -> only active doctors returned';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors',
                      ['active' => 1]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if ((int)($item['active'] ?? -1) !== 1) {
                    $passed = false;
                    break;
                }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "All " . count($body['data'] ?? []) . " records have active=1"
                : "A record with active != 1 was returned"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_006
        // Objective: getAll() with active=0 should filter and return only
        //            inactive doctors.
        //            SOURCE CODE BUG: `if($active)` — when $active=0, PHP treats
        //            this as falsy, so the WHERE clause is NOT applied. The API
        //            returns ALL doctors instead of only inactive ones.
        //            Expected (by design): only records with active=0
        //            Actual: all records returned (bug confirmed)
        // Input  : GET /doctors?active=0
        // Expected: result=1, every record has active == 0
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_006';
        $desc   = 'Bug check: GET /doctors?active=0 -> should return only inactive doctors';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors',
                      ['active' => 0]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        $hasActive1 = false;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if ((int)($item['active'] ?? -1) !== 0) {
                    $hasActive1 = true;
                    break;
                }
            }
        }
        if ($hasActive1) {
            $passed = false;
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "All returned records have active=0 (correct)"
                : "Records with active=1 were returned when filtering active=0"
                  . " -> Bug: `if(\$active)` in DoctorsController::getAll() line ~90"
                  . " treats active=0 as falsy, filter is never applied"
        );
        if (!$passed && $hasActive1) {
            $this->recordBug('BUG_DOC_001',
                'DoctorsController::getAll(): active=0 filter is ignored because'
                . ' `if($active)` evaluates 0 as falsy in PHP.'
                . ' Fix: change to `if($active !== "")` or `if(isset($active) && $active !== "")`'
            );
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_007
        // Objective: getAll() respects pagination (length parameter limits results)
        // Input  : GET /doctors?length=3&start=0
        // Expected: result=1, count(data) <= 3
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_007';
        $desc   = 'Standard: GET /doctors?length=3 -> at most 3 records returned';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors',
                      ['length' => 3, 'start' => 0]);
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 3;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Returned {$count} records (<= 3)"
                : "Returned {$count} records, expected <= 3"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_008
        // Objective: getAll() rejects requests with no auth token
        // Input  : GET /doctors (no Authorization header)
        // Expected: HTTP 302 redirect to login page, or result=0 / HTTP 401
        // -------------------------------------------------------------------
        $testId   = 'UT_DOC_008';
        $desc     = 'Edge case: No auth token -> access denied (redirect or 401)';
        $noAuth   = new HttpClient();
        $resp     = $noAuth->request('GET', API_BASE_URL . '/doctors');
        $body     = $resp['body'];
        $passed   = ($body['result'] ?? 1) == 0
                 || $resp['status'] == 302
                 || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Access correctly denied"
                : "Expected denial, got result=" . ($body['result'] ?? 'N/A')
                  . " HTTP {$resp['status']}"
        );
    }


    // =========================================================================
    // GROUP 2: CREATE DOCTOR
    // Tests DoctorsController::save() via POST /doctors
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Caller is not admin         -> 403-style rejection
    //   B2  Missing required field      -> result=0
    //   B3  Email bad format            -> result=0 (filter_var)
    //   B4  Email already exists        -> result=0 ($Doctor->isAvailable())
    //   B5  Name fails isVietnameseName -> result=0
    //   B6  Phone length < 10           -> result=0
    //   B7  Phone contains non-digits   -> result=0 (isNumber)
    //   B8  Price contains non-digits   -> result=0 (isNumber)
    //   B9  Price < 100000              -> result=0
    //   B10 Price == 100000 (boundary)  -> should PASS (not less than)
    //   B11 Role not in valid list       -> result=0
    //   B12 speciality_id not found     -> result=0
    //   B13 room_id not found           -> result=0
    //   B14 All valid                   -> result=1, CheckDB, Rollback
    //
    // KNOWN BUG UNDER TEST (DKH_41):
    //   Server echoes an SMTP error string before the JSON response.
    //   The HttpClient::request() handles this via extractJson().
    //   The actual record IS created; the bug is in server response format.
    //
    // KNOWN BUG UNDER TEST (DKH_41 price):
    //   price input 200000 is saved as 199999 in DB. Detected via CheckDB.
    // =========================================================================
    public function runCreateTests(): void {
        $this->printGroupHeader("GROUP 2: CREATE DOCTOR (POST /doctors)");

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_009
        // Objective: save() creates a doctor successfully when all inputs are valid
        //            Also verifies price is stored correctly in DB (Bug DKH_41 check)
        // Input  : email=unittest.doctor.new@gmail.com, phone=0901234560,
        //          name=Nguyen Van Test, description=Test doctor, price=200000,
        //          role=member, speciality_id=1, room_id=1
        // Expected: result=1 (or SMTP bug but record still in DB)
        //           CheckDB: record exists, email matches, role='member',
        //                    price stored == 200000 (will FAIL if DKH_41 price bug present)
        // Rollback: DELETE the created record in finally block
        // -------------------------------------------------------------------
        $testId    = 'UT_DOC_009';
        $desc      = 'Standard: POST valid doctor data -> result=1, CheckDB price/role, Rollback';
        $testEmail = 'unittest.doctor.new@gmail.com';
        $createdId = 0;

        // Cleanup any leftover record from a previous failed run (idempotency guard)
        $existing = $this->db->getDoctorByEmail($testEmail);
        if ($existing) {
            $this->db->rollbackDeleteDoctor((int)$existing['id']);
        }

        try {
            $resp  = $this->http->request('POST', API_BASE_URL . '/doctors', [
                'email'         => $testEmail,
                'phone'         => '0901234560',
                'name'          => 'Nguyen Van Test',
                'description'   => 'Test doctor automated',
                'price'         => 200000,
                'role'          => 'member',
                'speciality_id' => 1,
                'room_id'       => 1,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // Note if SMTP bug caused raw response corruption
            if ($resp['smtp_error']) {
                echo Color::yellow("       ⚠ SMTP garbage detected in raw response (Bug DKH_41)"
                    . " - parsing from JSON portion\n");
            }

            // CheckDB: Verify the record actually exists in tn_doctors
            $checkMsg = '';
            if ($this->db->connected) {
                $record = $this->db->getDoctorByEmail($testEmail);
                if (!$record) {
                    $passed   = false;
                    $checkMsg = 'CheckDB FAIL: Record not found in tn_doctors';
                } else {
                    $createdId   = (int)$record['id'];
                    $emailOk     = (strtolower($record['email']) === strtolower($testEmail));
                    $roleOk      = ($record['role'] === 'member');
                    $priceInDb   = (int)$record['price'];
                    $priceOk     = ($priceInDb === 200000);

                    if (!$emailOk || !$roleOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB FAIL: "
                            . (!$emailOk ? "email mismatch; " : "")
                            . (!$roleOk  ? "role='{$record['role']}' expected 'member'" : "");
                    } elseif (!$priceOk) {
                        // Record was created but price is wrong -> Bug
                        $checkMsg = "CheckDB FAIL (price bug): stored price={$priceInDb}"
                            . ", expected=200000 -> Bug DKH_41 price issue";
                        $passed = false;
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId},"
                            . " email='{$testEmail}', role='member', price=200000";
                    }

                    // Flag the price bug regardless of whether the overall test passes
                    if (!$priceOk) {
                        $this->recordBug('BUG_DOC_002',
                            "DoctorsController::save(): price=200000 input is saved as"
                            . " {$priceInDb} in DB. Suspected off-by-one or integer cast issue."
                        );
                    }
                }
            } else {
                $checkMsg = 'CheckDB skipped: no DB connection';
            }

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? $checkMsg
                    : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Always delete the test doctor record
            if ($createdId > 0) {
                $this->db->rollbackDeleteDoctor($createdId);
                $afterRollback = $this->db->getDoctorById($createdId);
                if ($afterRollback === null) {
                    echo Color::green("       ↳ Rollback OK: Test doctor ID={$createdId} deleted\n");
                } else {
                    echo Color::red("       ↳ Rollback FAILED: Doctor ID={$createdId} still exists\n");
                }
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_010
        // Objective: save() rejects request when required field 'email' is missing
        // Input  : POST without 'email' field
        // Expected: result=0, msg='Missing field: email'
        // Branch : foreach($required_fields) -> missing 'email'
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_010';
        $desc   = 'Edge case: Missing field email -> result=0 (Missing field: email)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            // 'email' intentionally omitted
            'phone' => '0901234561',
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_011
        // Objective: save() rejects request when required field 'name' is missing
        // Input  : POST without 'name' field
        // Expected: result=0, msg='Missing field: name'
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_011';
        $desc   = 'Edge case: Missing field name -> result=0 (Missing field: name)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.missingname@gmail.com',
            'phone' => '0901234562',
            // 'name' intentionally omitted
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_012
        // Objective: save() rejects email with invalid format
        // Input  : email='not-an-email' (no @ symbol)
        // Expected: result=0, msg='Email is not correct format. Try again !'
        // Branch : filter_var($email, FILTER_VALIDATE_EMAIL) === false
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_012';
        $desc   = 'Edge case: Invalid email format -> result=0 (Email is not correct format)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'not-an-email',
            'phone' => '0901234563',
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_013
        // Objective: save() rejects duplicate email (already registered)
        // Input  : email = ADMIN_EMAIL (already in DB)
        // Expected: result=0, msg='This email is used by someone. Try another !'
        // Branch : $Doctor->isAvailable() == true -> duplicate
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_013';
        $desc   = 'Edge case: Duplicate email -> result=0 (email already in use)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => ADMIN_EMAIL,
            'phone' => '0901234564',
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (duplicate email), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_014
        // Objective: save() rejects name containing digits or special characters
        // Input  : name='Nguyen Van 123!' (contains digits and !)
        // Expected: result=0, msg='Vietnamese name only has letters and space'
        // Branch : isVietnameseName($name) == 0
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_014';
        $desc   = 'Edge case: Name with digits/special chars -> result=0 (isVietnameseName fail)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.badname@gmail.com',
            'phone' => '0901234565',
            'name'  => 'Nguyen Van 123!',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (invalid name), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_015
        // Objective: save() rejects phone shorter than 10 characters
        // Input  : phone='090123' (6 characters)
        // Expected: result=0, msg='Phone number has at least 10 number !'
        // Branch : strlen($phone) < 10
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_015';
        $desc   = 'Edge case: Phone < 10 chars -> result=0 (phone too short)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.shortphone@gmail.com',
            'phone' => '090123',      // Only 6 characters
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (phone too short), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_016
        // Objective: save() rejects phone that contains non-digit characters
        // Input  : phone='abcdefghij' (10 chars but all letters)
        // Expected: result=0, msg='This is not a valid phone number...'
        // Branch : strlen >= 10 but isNumber($phone) returns false
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_016';
        $desc   = 'Edge case: Phone contains letters -> result=0 (isNumber fail)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.alphaphone@gmail.com',
            'phone' => 'abcdefghij',  // 10 chars, all letters
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (non-numeric phone), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_017
        // Objective: save() rejects price below the minimum threshold
        // Input  : price=50000 (less than 100000)
        // Expected: result=0, msg='Price must greater than 100.000 !'
        // Branch : $price < 100000
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_017';
        $desc   = 'Edge case: Price=50000 < 100000 -> result=0 (Price must greater than 100.000)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.lowprice@gmail.com',
            'phone' => '0901234566',
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
            'price' => 50000,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (price too low), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_018
        // Objective: save() accepts price exactly equal to the boundary value 100000
        //            Boundary analysis: condition is `$price < 100000`,
        //            so price=100000 must PASS validation.
        // Input  : price=100000 (boundary value), all other fields valid
        //          email unique to avoid collision
        // Expected: result=1, record created in DB
        // Rollback: DELETE the created record
        // -------------------------------------------------------------------
        $testId       = 'UT_DOC_018';
        $desc         = 'Boundary: Price=100000 (exactly at threshold) -> must PASS validation';
        $testEmail18  = 'unittest.price.boundary@gmail.com';
        $createdId18  = 0;

        $existing18 = $this->db->getDoctorByEmail($testEmail18);
        if ($existing18) {
            $this->db->rollbackDeleteDoctor((int)$existing18['id']);
        }

        try {
            $resp18 = $this->http->request('POST', API_BASE_URL . '/doctors', [
                'email'         => $testEmail18,
                'phone'         => '0901234570',
                'name'          => 'Nguyen Van Test',
                'price'         => 100000,       // Exactly at the boundary
                'role'          => 'member',
                'speciality_id' => 1,
                'room_id'       => 1,
            ]);
            $body18  = $resp18['body'];
            $passed  = ($body18['result'] ?? 0) == 1;

            $checkMsg18 = '';
            if ($this->db->connected) {
                $rec18 = $this->db->getDoctorByEmail($testEmail18);
                if ($rec18) {
                    $createdId18 = (int)$rec18['id'];
                    $checkMsg18  = "CheckDB OK: ID={$createdId18}, price stored="
                                . (int)$rec18['price'];
                } else {
                    $passed     = false;
                    $checkMsg18 = 'CheckDB FAIL: Record not found despite result=1';
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? $checkMsg18
                    : "result={$body18['result']}, msg=" . ($body18['msg'] ?? $checkMsg18)
            );
        } finally {
            if ($createdId18 > 0) {
                $this->db->rollbackDeleteDoctor($createdId18);
                echo Color::green("       ↳ Rollback OK: Doctor ID={$createdId18} deleted\n");
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_019
        // Objective: save() rejects an invalid role value
        // Input  : role='superuser' (not in ['admin','member','supporter'])
        // Expected: result=0, msg='Role is not valid...'
        // Branch : !in_array($role, $valid_roles)
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_019';
        $desc   = 'Edge case: Invalid role -> result=0 (Role is not valid)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email' => 'unittest.badrole@gmail.com',
            'phone' => '0901234567',
            'name'  => 'Nguyen Van Test',
            'role'  => 'superuser',   // Not in valid list
            'price' => 200000,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (invalid role), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_020
        // Objective: save() rejects non-existent speciality_id
        // Input  : speciality_id=999999 (does not exist in tn_specialities)
        // Expected: result=0, msg='Speciality is not available.'
        // Branch : !$Speciality->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_020';
        $desc   = 'Edge case: speciality_id=999999 does not exist -> result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email'         => 'unittest.badspeciality@gmail.com',
            'phone'         => '0901234568',
            'name'          => 'Nguyen Van Test',
            'role'          => 'member',
            'price'         => 200000,
            'speciality_id' => 999999,
            'room_id'       => 1,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (speciality not found), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_021
        // Objective: save() rejects non-existent room_id
        // Input  : room_id=999999 (does not exist in tn_rooms)
        // Expected: result=0, msg='Room is not available.'
        // Branch : !$Room->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_021';
        $desc   = 'Edge case: room_id=999999 does not exist -> result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email'         => 'unittest.badroom@gmail.com',
            'phone'         => '0901234569',
            'name'          => 'Nguyen Van Test',
            'role'          => 'member',
            'price'         => 200000,
            'speciality_id' => 1,
            'room_id'       => 999999,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (room not found), got result=" . ($body['result'] ?? 'N/A')
        );
    }


    // =========================================================================
    // GROUP 3: GET DOCTOR BY ID
    // Tests DoctorController::getById() via GET /doctors/{id}
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Valid ID         -> result=1, full data including speciality, room
    //   B2  ID not found     -> result=0, msg='Doctor is not available'
    //   B3  No auth token    -> HTTP 302 redirect
    // =========================================================================
    public function runGetByIdTests(): void {
        $this->printGroupHeader("GROUP 3: GET DOCTOR BY ID (GET /doctors/{id})");

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_022
        // Objective: getById() returns full doctor data for a valid ID
        // Input  : GET /doctors/1 (ID=1 exists in DB)
        // Expected: result=1, data contains: id, email, phone, name, price,
        //           role, active, speciality{id,name}, room{id,name}
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_022';
        $desc   = 'Standard: GET /doctors/1 -> result=1, full doctor object returned';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors/1');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data']['id'])
               && isset($body['data']['email'])
               && isset($body['data']['phone'])
               && isset($body['data']['name'])
               && isset($body['data']['price'])
               && isset($body['data']['role'])
               && isset($body['data']['active'])
               && isset($body['data']['speciality']['id'])
               && isset($body['data']['room']['id']);
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, id={$body['data']['id']}, name='{$body['data']['name']}'"
                : "result={$body['result']}, missing required fields in response"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_023
        // Objective: getById() returns error when the ID does not exist
        // Input  : GET /doctors/999999 (no such record)
        // Expected: result=0, msg='Doctor is not available'
        // Branch : !$Doctor->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_023';
        $desc   = 'Edge case: GET /doctors/999999 (nonexistent) -> result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_024
        // Objective: getById() rejects request with no auth token
        // Input  : GET /doctors/1 without Authorization header
        // Expected: HTTP 302 redirect or result=0 / HTTP 401
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_024';
        $desc   = 'Edge case: No auth token -> access denied';
        $noAuth = new HttpClient();
        $resp   = $noAuth->request('GET', API_BASE_URL . '/doctors/1');
        $passed = ($resp['body']['result'] ?? 1) == 0
               || $resp['status'] == 302
               || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Correctly denied"
                : "Expected denial, got HTTP={$resp['status']}"
        );
    }


    // =========================================================================
    // GROUP 4: UPDATE DOCTOR
    // Tests DoctorController::update() via PUT /doctors/{id}
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Caller is not admin             -> result=0
    //   B2  ID does not exist               -> result=0
    //   B3  Missing required field 'name'   -> result=0
    //   B4  Missing required field 'phone'  -> result=0
    //   B5  Name fails isVietnameseName     -> result=0
    //   B6  Phone < 10 chars                -> result=0
    //   B7  Phone contains non-digits       -> result=0
    //   B8  Price < 100000                  -> result=0
    //   B9  Role not valid                  -> result=0
    //   B10 All valid                       -> result=1, CheckDB, Rollback
    // =========================================================================
    public function runUpdateTests(): void {
        $this->printGroupHeader("GROUP 4: UPDATE DOCTOR (PUT /doctors/{id})");

        // Fetch original data for Doctor ID=1 to use in tests and for rollback
        $originalDoctor = $this->db->getDoctorById(1);
        if (!$originalDoctor) {
            echo Color::yellow("  [SKIP] Doctor ID=1 not found in DB - skipping Group 4\n");
            return;
        }
        $origPhone      = $originalDoctor['phone'];
        $origName       = $originalDoctor['name'];
        $origPrice      = $originalDoctor['price'];
        $origRole       = $originalDoctor['role'];
        $origActive     = $originalDoctor['active'];
        $origSpeciality = $originalDoctor['speciality_id'];
        $origRoom       = $originalDoctor['room_id'];
        $origDesc       = $originalDoctor['description'] ?? 'Test';

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_025
        // Objective: update() successfully updates doctor phone with valid data
        // Input  : PUT /doctors/1 with phone='0999888776', other fields unchanged
        // Expected: result=1
        //           CheckDB: phone in tn_doctors = '0999888776'
        // Rollback: Restore original phone value in finally block
        // -------------------------------------------------------------------
        $testId   = 'UT_DOC_025';
        $desc     = 'Standard: PUT /doctors/1 valid phone update -> result=1, CheckDB, Rollback';
        $newPhone = '0999888776';

        try {
            $resp = $this->http->request('PUT', API_BASE_URL . '/doctors/1', [
                'phone'         => $newPhone,
                'name'          => $origName,
                'description'   => $origDesc,
                'price'         => $origPrice,
                'role'          => $origRole,
                'active'        => $origActive,
                'speciality_id' => $origSpeciality,
                'room_id'       => $origRoom,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Confirm the phone was actually written to the database
            $checkMsg = '';
            if ($this->db->connected) {
                $updated = $this->db->getDoctorById(1);
                $phoneInDb = $updated['phone'] ?? '';
                if ($passed && $phoneInDb !== $newPhone) {
                    $passed   = false;
                    $checkMsg = "CheckDB FAIL: phone in DB='{$phoneInDb}', expected='{$newPhone}'";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: phone='{$phoneInDb}' (updated correctly)";
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? $checkMsg
                    : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Restore original phone regardless of test outcome
            $this->db->rollbackRestorePhone(1, $origPhone);
            $restored = $this->db->getDoctorById(1);
            if (($restored['phone'] ?? '') === $origPhone) {
                echo Color::green("       ↳ Rollback OK: phone restored to '{$origPhone}'\n");
            } else {
                echo Color::red("       ↳ Rollback FAILED: phone not restored\n");
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_026
        // Objective: update() rejects when the doctor ID does not exist
        // Input  : PUT /doctors/999999 (no such record)
        // Expected: result=0, msg='Doctor is not available. Try again !'
        // Branch : !$Doctor->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_026';
        $desc   = 'Edge case: PUT /doctors/999999 nonexistent -> result=0';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/doctors/999999', [
            'phone' => '0901234567',
            'name'  => 'Nguyen Van Test',
            'role'  => 'member',
            'price' => 200000,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_027
        // Objective: update() rejects when required field 'name' is absent
        // Input  : PUT /doctors/1 without 'name'
        // Expected: result=0, msg='Missing field: name'
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_027';
        $desc   = 'Edge case: PUT missing field name -> result=0 (Missing field: name)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/doctors/1', [
            'phone' => '0901234567',
            // 'name' intentionally omitted
            'role'  => 'member',
            'price' => 200000,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_028
        // Objective: update() rejects phone containing letter characters
        // Input  : PUT /doctors/1 phone='abcdefghij' (10 chars, all letters)
        // Expected: result=0, msg='This is not a valid phone number...'
        // Branch : isNumber($phone) returns false
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_028';
        $desc   = 'Edge case: PUT phone with letters -> result=0 (isNumber fail)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/doctors/1', [
            'phone' => 'abcdefghij',
            'name'  => $origName,
            'role'  => $origRole,
            'price' => $origPrice,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (non-numeric phone), got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_029
        // Objective: update() rejects price below 100000
        // Input  : PUT /doctors/1 price=99999
        // Expected: result=0, msg='Price must greater than 100.000 !'
        // Branch : $price < 100000
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_029';
        $desc   = 'Edge case: PUT price=99999 < 100000 -> result=0';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/doctors/1', [
            'phone' => '0901234567',
            'name'  => $origName,
            'role'  => $origRole,
            'price' => 99999,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0 (price too low), got result=" . ($body['result'] ?? 'N/A')
        );
    }


    // =========================================================================
    // GROUP 5: DELETE / DEACTIVATE DOCTOR
    // Tests DoctorController::delete() via DELETE /doctors/{id}
    //
    // DELETE LOGIC HAS TWO BRANCHES (from source code):
    //   Branch A: Doctor HAS appointments
    //             -> set all appointments to 'cancelled', set doctor active=0
    //             -> type='deactivated'
    //   Branch B: Doctor has NO appointments
    //             -> hard-delete the doctor record from DB
    //             -> type='delete'
    //
    // OTHER BRANCHES:
    //   B3  Admin deletes own account       -> result=0 (self-deactivation denied)
    //   B4  ID does not exist               -> result=0
    //   B5  Doctor already inactive (active=0) -> result=0
    // =========================================================================
    public function runDeleteTests(): void {
        $this->printGroupHeader("GROUP 5: DELETE / DEACTIVATE DOCTOR (DELETE /doctors/{id})");

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_030
        // Objective: delete() DEACTIVATES a doctor who has existing appointments
        //            (Branch A: count($quantityAppointment) > 0)
        // Input  : DELETE /doctors/{id} where the doctor has appointments
        // Expected: result=1, type='deactivated'
        //           CheckDB: record still in tn_doctors with active=0
        //           CheckDB: related appointments now have status='cancelled'
        // Rollback: Restore active=1 and restore appointment status in finally
        // -------------------------------------------------------------------
        $testId   = 'UT_DOC_030';
        $desc     = 'Branch A: DELETE doctor WITH appointments -> deactivate (active=0), type=deactivated';
        $doctorId = $this->db->getDoctorIdWithAppointment();

        if (!$doctorId) {
            $this->recordResult($testId, $desc, false,
                'SKIP: No active non-admin doctor with appointments found in DB');
        } else {
            try {
                $resp   = $this->http->request('DELETE',
                              API_BASE_URL . '/doctors/' . $doctorId);
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 0) == 1;

                // Verify type is 'deactivated' not 'delete'
                if ($passed && ($body['type'] ?? '') !== 'deactivated') {
                    $passed = false;
                }

                // CheckDB: doctor still in DB but active=0
                $checkMsg = '';
                if ($this->db->connected) {
                    $record = $this->db->getDoctorById($doctorId);
                    if (!$record) {
                        $passed   = false;
                        $checkMsg = "CheckDB FAIL: Doctor was HARD DELETED instead of deactivated!";
                    } elseif ((int)$record['active'] !== 0) {
                        $passed   = false;
                        $checkMsg = "CheckDB FAIL: active={$record['active']}, expected=0";
                    } else {
                        $checkMsg = "CheckDB OK: Doctor ID={$doctorId} still in DB, active=0";
                    }

                    // CheckDB: verify appointments were set to cancelled
                    $appts = $this->db->query(
                        "SELECT COUNT(*) as cnt FROM " . TABLE_APPOINTMENTS
                        . " WHERE doctor_id = ? AND status != 'cancelled'",
                        [$doctorId]
                    );
                    $nonCancelledCount = (int)($appts[0]['cnt'] ?? -1);
                    if ($nonCancelledCount > 0) {
                        $checkMsg .= " | CheckDB WARN: {$nonCancelledCount} appointments NOT cancelled";
                    } else {
                        $checkMsg .= " | All appointments set to 'cancelled'";
                    }
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "type='deactivated', {$checkMsg}"
                        : "type='" . ($body['type'] ?? 'N/A') . "', {$checkMsg}"
                );
            } finally {
                // Rollback: Restore active=1 and un-cancel the appointments
                $this->db->rollbackRestoreActive($doctorId);
                $restored = $this->db->getDoctorById($doctorId);
                if ((int)($restored['active'] ?? -1) === 1) {
                    echo Color::green("       ↳ Rollback OK: Doctor active restored to 1\n");
                } else {
                    echo Color::red("       ↳ Rollback FAILED: active not restored\n");
                }
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_031
        // Objective: delete() HARD DELETES a doctor who has NO appointments
        //            (Branch B: count($quantityAppointment) == 0)
        //            A new test doctor is created first so no existing data is
        //            at risk.
        // Input  : Create test doctor -> DELETE it
        // Expected: result=1, type='delete'
        //           CheckDB: record no longer exists in tn_doctors
        // Rollback: If API failed and record remains, delete it manually
        // -------------------------------------------------------------------
        $testId      = 'UT_DOC_031';
        $desc        = 'Branch B: DELETE doctor WITHOUT appointments -> hard delete, type=delete';
        $testEmail31 = 'unittest.delete.nobooking@gmail.com';
        $createdId31 = 0;

        // Cleanup any leftover from a previous failed run
        $leftover = $this->db->getDoctorByEmail($testEmail31);
        if ($leftover) {
            $this->db->rollbackDeleteDoctor((int)$leftover['id']);
        }

        // Step 1: Create a fresh test doctor (no appointments will exist for it)
        $createResp = $this->http->request('POST', API_BASE_URL . '/doctors', [
            'email'         => $testEmail31,
            'phone'         => '0911000099',
            'name'          => 'Bac Si Xoa Thu Nghiem',
            'price'         => 200000,
            'role'          => 'member',
            'speciality_id' => 1,
            'room_id'       => 1,
        ]);

        $createBody = $createResp['body'];
        $createOk   = ($createBody['result'] ?? 0) == 1;

        // Even if result=0 due to SMTP bug, check DB directly
        if (!$createOk && $createResp['smtp_error']) {
            $createOk = true; // SMTP error but doctor may still be in DB
        }

        if ($createOk) {
            $record31    = $this->db->getDoctorByEmail($testEmail31);
            $createdId31 = $record31 ? (int)$record31['id'] : 0;
        }

        if ($createdId31 === 0) {
            $this->recordResult($testId, $desc, false,
                'SKIP: Could not create test doctor - ' . ($createBody['msg'] ?? 'unknown error'));
        } else {
            try {
                // Step 2: Delete the test doctor (no appointments exist)
                $deleteResp = $this->http->request('DELETE',
                                  API_BASE_URL . '/doctors/' . $createdId31);
                $deleteBody = $deleteResp['body'];
                $passed     = ($deleteBody['result'] ?? 0) == 1
                           && ($deleteBody['type'] ?? '') === 'delete';

                // CheckDB: Record must no longer exist
                $checkMsg = '';
                if ($this->db->connected) {
                    $afterDelete = $this->db->getDoctorById($createdId31);
                    if ($afterDelete !== null) {
                        $passed   = false;
                        $checkMsg = "CheckDB FAIL: Record ID={$createdId31} still in DB"
                            . " (expected hard delete)";
                    } else {
                        $checkMsg  = "CheckDB OK: Record ID={$createdId31} removed from DB";
                        $createdId31 = 0; // Successfully deleted; no rollback needed
                    }
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "type='delete', {$checkMsg}"
                        : "result={$deleteBody['result']}, type='"
                          . ($deleteBody['type'] ?? 'N/A') . "', {$checkMsg}"
                );
            } finally {
                // Rollback: If delete failed and record still exists, remove it
                if ($createdId31 > 0) {
                    $this->db->rollbackDeleteDoctor($createdId31);
                    echo Color::yellow(
                        "       ↳ Rollback: Cleaned up leftover doctor ID={$createdId31}\n"
                    );
                }
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_032
        // Objective: delete() rejects when the admin tries to delete their own account
        // Input  : DELETE /doctors/{id of current admin}
        // Expected: result=0, msg='You can not deactivate yourself !'
        // Branch : $AuthUser->get("id") == $Route->params->id
        // -------------------------------------------------------------------
        $testId      = 'UT_DOC_032';
        $desc        = 'Edge case: Admin deletes own account -> result=0 (self-deactivation denied)';
        $adminRecord = $this->db->getDoctorByEmail(ADMIN_EMAIL);
        $adminId     = $adminRecord ? (int)$adminRecord['id'] : 0;

        if ($adminId > 0) {
            $resp   = $this->http->request('DELETE', API_BASE_URL . '/doctors/' . $adminId);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
            );
        } else {
            $this->recordResult($testId, $desc, false,
                'SKIP: Could not retrieve admin ID from DB');
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_033
        // Objective: delete() rejects when the doctor ID does not exist
        // Input  : DELETE /doctors/999999
        // Expected: result=0, msg='Doctor is not available !'
        // Branch : !$Doctor->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_DOC_033';
        $desc   = 'Edge case: DELETE /doctors/999999 (nonexistent) -> result=0';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/doctors/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_DOC_034
        // Objective: delete() rejects when the doctor is already inactive (active=0)
        //            This covers the check: if($Doctor->get("active") != 1)
        // Input  : DELETE /doctors/{id of an already-deactivated doctor}
        //          If no inactive doctor exists, one is created and deactivated first.
        // Expected: result=0, msg='This doctor account was deactivated...'
        // Rollback: If a temporary doctor was created, clean up afterward
        // -------------------------------------------------------------------
        $testId     = 'UT_DOC_034';
        $desc       = 'Edge case: DELETE already-inactive doctor (active=0) -> result=0';
        $inactiveId = 0;
        $createdForTest34 = false;
        $testEmail34 = 'unittest.already.inactive@gmail.com';

        // First, look for an existing inactive doctor in the DB
        $inactiveRows = $this->db->query(
            "SELECT id FROM " . TABLE_DOCTORS
            . " WHERE active = 0 AND role != 'admin' LIMIT 1"
        );
        if (!empty($inactiveRows)) {
            $inactiveId = (int)$inactiveRows[0]['id'];
        }

        // If no inactive doctor exists, create one and deactivate it manually
        if ($inactiveId === 0) {
            $leftover34 = $this->db->getDoctorByEmail($testEmail34);
            if ($leftover34) {
                $this->db->rollbackDeleteDoctor((int)$leftover34['id']);
            }

            $createResp34 = $this->http->request('POST', API_BASE_URL . '/doctors', [
                'email'         => $testEmail34,
                'phone'         => '0922333444',
                'name'          => 'Bac Si Da Vo Hieu',
                'price'         => 200000,
                'role'          => 'member',
                'speciality_id' => 1,
                'room_id'       => 1,
            ]);
            $rec34 = $this->db->getDoctorByEmail($testEmail34);
            if ($rec34) {
                $inactiveId = (int)$rec34['id'];
                // Deactivate manually (set active=0 directly in DB)
                $this->db->execute(
                    "UPDATE " . TABLE_DOCTORS . " SET active = 0 WHERE id = ?",
                    [$inactiveId]
                );
                $createdForTest34 = true;
            }
        }

        if ($inactiveId === 0) {
            $this->recordResult($testId, $desc, false,
                'SKIP: Could not find or create an inactive doctor for this test');
        } else {
            try {
                $resp   = $this->http->request('DELETE',
                              API_BASE_URL . '/doctors/' . $inactiveId);
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 1) == 0;
                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, msg='{$body['msg']}'"
                        : "Expected result=0 (already inactive), got result="
                          . ($body['result'] ?? 'N/A')
                );
            } finally {
                // Rollback: Only clean up the doctor we created for this test
                if ($createdForTest34 && $inactiveId > 0) {
                    $this->db->rollbackDeleteDoctor($inactiveId);
                    echo Color::green(
                        "       ↳ Rollback OK: Test doctor ID={$inactiveId} cleaned up\n"
                    );
                }
            }
        }
    }


    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================
    public function run(string $group = 'all'): void {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════════╗\n" .
            "║   UNIT TEST - DOCTORS MODULE - UMBRELLA CORPORATION     ║\n" .
            "║   PHP Test Script  |  CLI output only                   ║\n" .
            "╚══════════════════════════════════════════════════════════╝"
        )) . "\n";
        echo Color::yellow("  API URL   : " . API_BASE_URL) . "\n";
        echo Color::yellow("  Timestamp : " . date('Y-m-d H:i:s')) . "\n";
        echo Color::yellow("  Database  : " . DB_NAME . "@" . DB_HOST) . "\n";
        echo Color::yellow("  DB Status : " . ($this->db->connected
            ? Color::green('✓ Connected')
            : Color::red('✗ Not connected'))) . "\n";

        $this->printGroupHeader("LOGIN (not counted as a test case)");
        $this->doLogin();

        switch ($group) {
            case 'list':   $this->runListTests();      break;
            case 'create': $this->runCreateTests();    break;
            case 'detail': $this->runGetByIdTests();   break;
            case 'update': $this->runUpdateTests();    break;
            case 'delete': $this->runDeleteTests();    break;
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runGetByIdTests();
                $this->runUpdateTests();
                $this->runDeleteTests();
                break;
        }

        $this->printSummary();
        $this->db->close();
    }
}

// =============================================================================
// PROGRAM ENTRY POINT
// =============================================================================
$group  = $argv[1] ?? 'all';
$runner = new TestRunner();
$runner->run($group);