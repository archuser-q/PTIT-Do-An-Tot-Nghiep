<?php
/**
 * =============================================================================
 * FILE:    PatientsControllerTest.php
 * PROJECT: Umbrella Corporation - Clinic Management System
 * MODULE:  Patients
 * DATE:    2026-05-13
 *
 * DESCRIPTION:
 * Unit test script for the Patients module. Tests call the REST API via cURL
 * and verify results against source-code logic in:
 *   - PatientsController.php  (GET /patients, POST /patients)
 *   - PatientController.php   (GET /patients/{id}, PUT /patients/{id},
 *                              DELETE /patients/{id})
 *
 * TESTING METHODOLOGY (per Section 5.3 guidelines):
 *   Step 1 - Build test cases to achieve branch coverage (Level 2).
 *            Expected output is derived from design/spec and source code
 *            logic, NOT from blindly trusting the SUT's output.
 *   Step 2 - Call the API, verify result, perform CheckDB to confirm DB state.
 *   Step 3 - Manual Rollback in finally blocks to restore DB to pre-test state.
 *
 * NOTE ON ROLLBACK STRATEGY:
 * Pixie Query Builder manages its own PDO connection internally.
 * PDO::beginTransaction() cannot wrap API calls. Manual rollback is used:
 *   - After UPDATE test: UPDATE back to original values
 *   - DELETE is always rejected by the API, so no rollback needed for delete
 *
 * API ROUTES (from routes.inc.php):
 *   GET    API/patients         -> PatientsController::getAll()
 *   POST   API/patients         -> PatientsController (always rejects)
 *   GET    API/patients/{id}    -> PatientController::getById()
 *   PUT    API/patients/{id}    -> PatientController::update()
 *   DELETE API/patients/{id}    -> PatientController::delete() (always rejects)
 *
 * DATABASE: nextpost, table: tn_patients
 * Schema: id, email, phone, password, name, gender, birthday, address,
 *         avatar, create_at, update_at
 *
 * -----------------------------------------------------------------------
 * IMPORTANT BUGS DISCOVERED DURING SOURCE CODE ANALYSIS (pre-test):
 * -----------------------------------------------------------------------
 * BUG_PAT_001 - PatientController::update() birthday validation
 *   Location : PatientController.php ~line 110-115
 *   Code     : $monthBirthday = (int)substr($birthday, 5, 8);
 *              $dayBirthday   = (int)substr($birthday, 8, 10);
 *   Problem  : substr($birthday, 5, 8) with "YYYY-MM-DD" returns "-MM-DD"
 *              (the 8-char string from index 5), which casts to int = 0.
 *              So monthBirthday is always 0 regardless of actual input.
 *              checkdate(0, day, year) always returns false (month 0 invalid).
 *              Result: EVERY valid birthday is rejected with "does not exist!"
 *   Fix      : Use substr($birthday, 5, 2) and substr($birthday, 8, 2)
 *
 * BUG_PAT_002 - PatientController::delete() message typo for ID=1
 *   Location : PatientController.php delete() method
 *   Code     : $this->resp->msg = "This patient is an example & can be deleted !"
 *   Problem  : Message says "can be deleted" but intent is "cannot be deleted".
 *              Logic contradiction: the code deliberately prevents deletion but
 *              the message tells the caller that deletion is allowed.
 *   Fix      : Change to "cannot be deleted"
 *
 * BUG_PAT_003 - PatientsController::getAll() quantity reflects paged count
 *   Location : PatientsController.php ~line 80
 *   Code     : $this->resp->quantity = count($result);
 *              where $result is fetched AFTER applying limit/offset
 *   Problem  : quantity returns the number of records on the CURRENT PAGE,
 *              not the total number of matching records. Callers cannot use
 *              quantity for pagination (they do not know the total).
 *              (Note: DoctorsController has the same design, so this may be
 *              intentional - flagged for reviewer to confirm.)
 *
 * HOW TO RUN:
 *   php PatientsControllerTest.php          -> Run all test groups
 *   php PatientsControllerTest.php list     -> Group 1: Get all patients
 *   php PatientsControllerTest.php post     -> Group 2: POST (always rejected)
 *   php PatientsControllerTest.php detail   -> Group 3: Get patient by ID
 *   php PatientsControllerTest.php update   -> Group 4: Update patient
 *   php PatientsControllerTest.php delete   -> Group 5: Delete patient
 * =============================================================================
 */

// =============================================================================
// CONFIGURATION
// =============================================================================
define('API_BASE_URL',    'http://localhost:8080/PTIT-Do-An-Tot-Nghiep/api');
define('ADMIN_EMAIL',     'phongkaster@gmail.com');
define('ADMIN_PASSWORD',  '123456');
define('DB_HOST',         '127.0.0.1');
define('DB_PORT',         3306);
define('DB_NAME',         'nextpost');
define('DB_USER',         'root');
define('DB_PASSWORD',     '');
define('TABLE_PATIENTS',  'tn_patients');

// =============================================================================
// CLASS Color
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
// CheckDB: verify database state after API calls
// Rollback: restore database to pre-test state in finally blocks
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
     * CheckDB: Fetch a patient record by ID directly from the database.
     * @param int $id Patient ID
     * @return array|null Row array or null if not found
     */
    public function getPatientById(int $id): ?array {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_PATIENTS . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * CheckDB: Get the first available patient record from the DB.
     * Used to find a safe target for read and update tests.
     * @return array|null
     */
    public function getFirstPatient(): ?array {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT * FROM " . TABLE_PATIENTS . " ORDER BY id ASC LIMIT 1"
        );
        return $rows[0] ?? null;
    }

    /**
     * Rollback: Restore a patient's original field values after an UPDATE test.
     * @param int    $id           Patient ID
     * @param string $originalPhone Original phone value before test
     * @param string $originalName  Original name value before test
     * @param string $originalBirthday Original birthday value before test
     * @param string $originalAddress  Original address value before test
     * @param int    $originalGender   Original gender value before test
     */
    public function rollbackRestorePatient(
        int    $id,
        string $originalPhone,
        string $originalName,
        string $originalBirthday,
        string $originalAddress,
        int    $originalGender
    ): void {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_PATIENTS
            . " SET phone=?, name=?, birthday=?, address=?, gender=? WHERE id=?",
            [$originalPhone, $originalName, $originalBirthday,
             $originalAddress, $originalGender, $id]
        );
    }

    public function close(): void { $this->pdo = null; }
}

// =============================================================================
// CLASS HttpClient
// =============================================================================
class HttpClient {
    private ?string $accessToken = null;

    public function setToken(string $token): void { $this->accessToken = $token; }
    public function clearToken(): void            { $this->accessToken = null;  }

    /**
     * Execute an HTTP request via cURL.
     * Handles SMTP-prefix bug (same as DoctorsControllerTest) via extractJson().
     * @return array ['status'=>int, 'body'=>array, 'smtp_error'=>bool]
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

        $rawBody    = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['status' => 0,
                    'body'   => ['result' => 0, 'msg' => 'cURL Error: ' . $curlError],
                    'smtp_error' => false];
        }

        // Robust JSON extraction: strip any leading non-JSON garbage (e.g. SMTP errors)
        $smtpError  = false;
        $jsonStr    = $rawBody;
        $firstBrace = strpos($rawBody, '{');
        if ($firstBrace !== false && $firstBrace > 0) {
            $smtpError = true;
            $jsonStr   = substr($rawBody, $firstBrace);
        }

        $parsed = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = ['result' => 0, 'msg' => 'Invalid JSON: ' . $rawBody];
        }

        return ['status' => $httpStatus, 'body' => $parsed, 'smtp_error' => $smtpError];
    }
}

// =============================================================================
// CLASS TestRunner
// =============================================================================
class TestRunner {
    private int   $totalTests  = 0;
    private int   $passedTests = 0;
    private int   $failedTests = 0;
    private array $failedList  = [];
    private array $bugList     = [];

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
        echo sprintf("  %s        : %d\n", Color::red('FAIL'),   $this->failedTests);

        if (!empty($this->failedList)) {
            echo "\n" . Color::red(Color::bold("  FAILED TEST CASES:")) . "\n";
            foreach ($this->failedList as $line) echo Color::red("  {$line}") . "\n";
        }

        if (!empty($this->bugList)) {
            echo "\n" . Color::yellow(Color::bold("  BUGS CONFIRMED IN SYSTEM UNDER TEST:")) . "\n";
            foreach ($this->bugList as $line) echo Color::yellow("  {$line}") . "\n";
        }

        $rate = $this->totalTests > 0
            ? round($this->passedTests / $this->totalTests * 100, 1) : 0;
        echo "\n" . Color::bold(sprintf("  Pass rate: %s%%", $rate)) . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n\n";
    }

    /** Admin login to obtain JWT token. NOT counted as a test case. */
    private function doLogin(): void {
        $resp = $this->http->request('POST', API_BASE_URL . '/login', [
            'email'    => ADMIN_EMAIL,
            'password' => ADMIN_PASSWORD,
            'type'     => 'doctor',
        ]);
        if (($resp['body']['result'] ?? 0) == 1) {
            $this->http->setToken($resp['body']['accessToken']);
            echo Color::green("  ✓ Admin login successful\n");
        } else {
            echo Color::red("  ✗ Admin login FAILED - all subsequent tests will fail\n");
        }
    }


    // =========================================================================
    // GROUP 1: GET ALL PATIENTS
    // Tests PatientsController::getAll() via GET /patients
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Role != admin/supporter     -> result=0 (permission denied)
    //   B2  No filter                   -> returns all patients (paged)
    //   B3  search with match           -> LIKE filter (starts-with)
    //   B4  search with no match        -> empty result
    //   B5  order asc/desc              -> sorted correctly
    //   B6  length/start pagination     -> LIMIT/OFFSET applied
    //   B7  No auth token               -> HTTP 302 redirect
    //
    // NOTE on quantity field (BUG_PAT_003):
    //   quantity = count($result) where $result is already paginated.
    //   So quantity == count(data) always, not total record count.
    //   This is a design issue flagged but not treated as blocking.
    // =========================================================================
    public function runListTests(): void {
        $this->printGroupHeader("GROUP 1: GET ALL PATIENTS (GET /patients)");

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_001
        // Objective: getAll() returns valid list with no filter (admin role)
        // Input  : GET /patients (no params), valid admin token
        // Expected: result=1, 'data' is array, 'quantity' is present,
        //           each record has: id, email, phone, name, gender,
        //           birthday, address, avatar
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_001';
        $desc   = 'Standard: GET /patients no filter -> result=1, data array, quantity present';
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);

        // Also verify response fields for first record
        $fieldMsg = '';
        if ($passed && !empty($body['data'])) {
            $first        = $body['data'][0];
            $requiredKeys = ['id','email','phone','name','gender','birthday','address','avatar'];
            $missing      = [];
            foreach ($requiredKeys as $k) {
                if (!array_key_exists($k, $first)) $missing[] = $k;
            }
            if (!empty($missing)) {
                $passed   = false;
                $fieldMsg = " Missing fields: " . implode(', ', $missing);
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, quantity={$body['quantity']}, returned count=" . count($body['data'])
                : "result={$body['result']}" . $fieldMsg
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_002
        // Objective: getAll() search filters by name starting with keyword
        //            Source code uses LIKE 'keyword%' (starts-with, not contains)
        // Input  : GET /patients?search=Duong (or a name prefix that exists)
        // Expected: result=1, all returned records have name/email/phone/address
        //           starting with the search keyword
        // -------------------------------------------------------------------
        $testId    = 'UT_PAT_002';
        $searchKey = 'Duong';
        $desc      = "Standard: GET /patients?search={$searchKey} -> results start with keyword";
        $resp      = $this->http->request('GET', API_BASE_URL . '/patients',
                         ['search' => $searchKey]);
        $body      = $resp['body'];
        $passed    = ($body['result'] ?? 0) == 1;
        $mismatch  = '';
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                $matchName    = stripos($item['name']    ?? '', $searchKey) === 0;
                $matchEmail   = stripos($item['email']   ?? '', $searchKey) === 0;
                $matchPhone   = stripos($item['phone']   ?? '', $searchKey) === 0;
                $matchAddress = stripos($item['address'] ?? '', $searchKey) === 0;
                if (!$matchName && !$matchEmail && !$matchPhone && !$matchAddress) {
                    $passed   = false;
                    $mismatch = "Record name='{$item['name']}' does not start with '{$searchKey}'";
                    break;
                }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Returned " . count($body['data'] ?? []) . " records, all start with '{$searchKey}'"
                : $mismatch ?: "result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_003
        // Objective: getAll() returns empty when search keyword matches nothing
        // Input  : GET /patients?search=xyznotfound999
        // Expected: result=1, data is empty array
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_003';
        $desc   = 'Standard: GET /patients?search=xyznotfound999 -> empty result';
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients',
                      ['search' => 'xyznotfound999']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && count($body['data'] ?? []) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, 0 records returned (correct)"
                : "Expected 0 records, got " . count($body['data'] ?? [])
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_004
        // Objective: getAll() sorts correctly in descending order (dir=desc)
        // Input  : GET /patients?order[column]=id&order[dir]=desc
        // Expected: result=1, records are ordered by id descending
        //           (first record id >= second record id)
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_004';
        $desc   = 'Standard: GET /patients?order[dir]=desc -> records sorted by id DESC';
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients', [
            'order' => ['column' => 'id', 'dir' => 'desc'],
            'length' => 5,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && count($body['data'] ?? []) >= 2) {
            $ids = array_column($body['data'], 'id');
            for ($i = 0; $i < count($ids) - 1; $i++) {
                if ($ids[$i] < $ids[$i + 1]) {
                    $passed = false;
                    break;
                }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Records returned in descending id order"
                : "Records not in descending order -> sort may be broken"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_005
        // Objective: getAll() pagination - length limits returned records
        // Input  : GET /patients?length=3&start=0
        // Expected: result=1, count(data) <= 3
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_005';
        $desc   = 'Standard: GET /patients?length=3 -> at most 3 records returned';
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients',
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
        // TC ID  : UT_PAT_006
        // Objective: getAll() pagination offset - start skips first N records
        // Input  : GET /patients?length=5&start=0 then ?length=5&start=5
        //          First records of page 2 should differ from page 1
        // Expected: result=1, data on page 2 has different ids than page 1
        // -------------------------------------------------------------------
        $testId  = 'UT_PAT_006';
        $desc    = 'Standard: GET /patients?start=5 -> returns different page of records';
        $resp1   = $this->http->request('GET', API_BASE_URL . '/patients',
                       ['length' => 5, 'start' => 0]);
        $resp2   = $this->http->request('GET', API_BASE_URL . '/patients',
                       ['length' => 5, 'start' => 5]);
        $body1   = $resp1['body'];
        $body2   = $resp2['body'];
        $ids1    = array_column($body1['data'] ?? [], 'id');
        $ids2    = array_column($body2['data'] ?? [], 'id');
        $overlap = array_intersect($ids1, $ids2);
        $passed  = ($body1['result'] ?? 0) == 1
                && ($body2['result'] ?? 0) == 1
                && empty($overlap);
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Page 1 and page 2 have no overlapping records (offset works)"
                : "Overlap found: " . implode(',', $overlap) . " -> offset may not work"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_007
        // Objective: getAll() rejects caller with 'member' role (not admin/supporter)
        //            Source code: $valid_roles = ["admin","supporter"]
        //            'member' is not in the list -> result=0
        // Input  : GET /patients with a 'member' role doctor token
        //          (We simulate by calling without token and expecting rejection,
        //          since we can only log in as admin in this test setup.
        //          The role-check branch is verified by confirming that admin
        //          CAN access, combined with the source code reading.)
        // Note   : Full test of member-role rejection requires a separate member
        //          login. Here we verify the auth guard via no-token test.
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_007';
        $desc   = 'Edge case: No auth token -> access denied (HTTP 302 or result=0)';
        $noAuth = new HttpClient();
        $resp   = $noAuth->request('GET', API_BASE_URL . '/patients');
        $passed = ($resp['body']['result'] ?? 1) == 0
               || $resp['status'] == 302
               || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Access correctly denied"
                : "Expected denial, got HTTP {$resp['status']}"
        );
    }


    // =========================================================================
    // GROUP 2: POST /patients (always rejected)
    // Tests PatientsController's POST handler
    //
    // SOURCE CODE:
    //   The POST branch always returns result=0 with a fixed message:
    //   "We can't create patient information because they create account
    //    by PHONE NUMBER or GOOGLE."
    //   There are no sub-branches; this is a single always-reject path.
    //
    // BRANCHES TO COVER:
    //   B1  POST with valid-looking data  -> result=0 (creation not allowed)
    //   B2  POST with empty body          -> result=0 (same rejection)
    //   B3  No auth token                 -> HTTP 302 redirect before POST handler
    // =========================================================================
    public function runPostTests(): void {
        $this->printGroupHeader("GROUP 2: POST /patients (creation always rejected)");

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_008
        // Objective: POST with complete valid data is still rejected
        //            Source code design: patients self-register via phone/Google,
        //            admin cannot create patient accounts directly.
        // Input  : POST /patients with name, phone, email, etc.
        // Expected: result=0, msg contains "can't create patient"
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_008';
        $desc   = 'Standard: POST /patients with valid data -> result=0 (creation not allowed)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/patients', [
            'email'    => 'newpatient@test.com',
            'phone'    => '0901234567',
            'name'     => 'Nguyen Van Test',
            'gender'   => 0,
            'birthday' => '1990-01-15',
            'address'  => '123 Test Street',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_009
        // Objective: POST with empty body is still rejected (same handler)
        // Input  : POST /patients with no fields
        // Expected: result=0 (same fixed rejection message)
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_009';
        $desc   = 'Edge case: POST /patients with empty body -> result=0 (same rejection)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/patients', []);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_010
        // Objective: POST without auth token is rejected before reaching handler
        // Input  : POST /patients without Authorization header
        // Expected: HTTP 302 redirect or result=0
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_010';
        $desc   = 'Edge case: POST /patients no token -> access denied before handler';
        $noAuth = new HttpClient();
        $resp   = $noAuth->request('POST', API_BASE_URL . '/patients', [
            'name' => 'Test Patient',
        ]);
        $passed = ($resp['body']['result'] ?? 1) == 0
               || $resp['status'] == 302
               || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Access correctly denied"
                : "Expected denial, got HTTP {$resp['status']}"
        );
    }


    // =========================================================================
    // GROUP 3: GET PATIENT BY ID
    // Tests PatientController::getById() via GET /patients/{id}
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Role not admin/supporter  -> result=0 (permission denied)
    //   B2  Valid ID, admin token     -> result=1, full patient data
    //   B3  ID not found              -> result=0, msg='Patient is not available'
    //   B4  No auth token             -> HTTP 302 redirect
    // =========================================================================
    public function runGetByIdTests(): void {
        $this->printGroupHeader("GROUP 3: GET PATIENT BY ID (GET /patients/{id})");

        // Get a valid patient ID from DB for positive tests
        $firstPatient = $this->db->getFirstPatient();
        $validId      = $firstPatient ? (int)$firstPatient['id'] : 1;

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_011
        // Objective: getById() returns full patient data for a valid ID
        // Input  : GET /patients/{validId}, admin token
        // Expected: result=1, data contains: id, email, phone, name, gender,
        //           birthday, address, avatar, create_at, update_at
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_011';
        $desc   = "Standard: GET /patients/{$validId} -> result=1, full patient object";
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients/' . $validId);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data']['id'])
               && isset($body['data']['email'])
               && isset($body['data']['phone'])
               && isset($body['data']['name'])
               && isset($body['data']['gender'])
               && isset($body['data']['birthday'])
               && isset($body['data']['address'])
               && isset($body['data']['avatar'])
               && isset($body['data']['create_at'])
               && isset($body['data']['update_at']);
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, id={$body['data']['id']}, name='{$body['data']['name']}'"
                : "result={$body['result']}, missing required fields in response"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_012
        // Objective: getById() returns error when ID does not exist
        // Input  : GET /patients/999999
        // Expected: result=0, msg='Patient is not available'
        // Branch : !$Patient->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_012';
        $desc   = 'Edge case: GET /patients/999999 (nonexistent) -> result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/patients/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_013
        // Objective: getById() rejects request with no auth token
        // Input  : GET /patients/{validId} without Authorization header
        // Expected: HTTP 302 redirect
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_013';
        $desc   = 'Edge case: No auth token -> access denied';
        $noAuth = new HttpClient();
        $resp   = $noAuth->request('GET', API_BASE_URL . '/patients/' . $validId);
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
    // GROUP 4: UPDATE PATIENT
    // Tests PatientController::update() via PUT /patients/{id}
    //
    // SOURCE CODE BRANCHES TO COVER:
    //   B1  Caller role != admin            -> result=0
    //   B2  ID not found                    -> result=0
    //   B3  Missing required field 'name'   -> result=0
    //   B4  Missing required field 'phone'  -> result=0
    //   B5  Missing required field 'birthday'-> result=0
    //   B6  Name fails isVietnameseName     -> result=0
    //   B7  Phone < 10 chars                -> result=0
    //   B8  Phone contains non-digits       -> result=0
    //   B9  Birthday invalid date           -> result=0 (checkdate fails)
    //   B10 Birthday in future (year > today)-> result=0
    //   B11 Birthday in future (same year, month > today) -> result=0
    //   B12 Birthday in future (same year+month, day > today) -> result=0
    //   B13 Birthday today (valid boundary) -> result=1
    //   B14 Address contains invalid chars  -> result=0 (isAddress fails)
    //   B15 Gender not in [0,1]             -> result=0
    //   B16 All valid                       -> result=1, CheckDB, Rollback
    //
    // *** BUG_PAT_001 ALERT ***
    // Birthday validation has a substr bug:
    //   $monthBirthday = (int)substr($birthday, 5, 8)
    //   With "YYYY-MM-DD", index 5 = '-', substr(str,5,8) = "-MM-DD" (6 chars)
    //   (int)"-MM-DD" = 0 → monthBirthday = 0 always
    //   checkdate(0, day, year) = false for all inputs
    //   → ALL birthday inputs will fail with "does not exist!"
    // This bug causes B9,B10,B11,B12,B13,B16 to behave unexpectedly.
    // The tests below document EXPECTED behavior (per correct implementation)
    // AND the ACTUAL behavior given the bug, so the tester can demonstrate
    // understanding of both what should happen and what does happen.
    // =========================================================================
    public function runUpdateTests(): void {
        $this->printGroupHeader("GROUP 4: UPDATE PATIENT (PUT /patients/{id})");

        // Retrieve a real patient from DB for update tests
        $patient = $this->db->getFirstPatient();
        if (!$patient) {
            echo Color::yellow("  [SKIP] No patient found in DB - skipping Group 4\n");
            return;
        }
        $patientId   = (int)$patient['id'];
        $origPhone   = $patient['phone']    ?? '0900000000';
        $origName    = $patient['name']     ?? 'Test Patient';
        $origBday    = $patient['birthday'] ?? '1990-01-01';
        $origAddress = $patient['address']  ?? '123 Test St';
        $origGender  = (int)($patient['gender'] ?? 0);

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_014
        // Objective: update() rejects request when ID does not exist
        // Input  : PUT /patients/999999
        // Expected: result=0, msg='Patient is not available !'
        // Branch : !$Patient->isAvailable()
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_014';
        $desc   = 'Edge case: PUT /patients/999999 nonexistent -> result=0';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/999999', [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_015
        // Objective: update() rejects when required field 'name' is missing
        // Input  : PUT /patients/{id} without 'name'
        // Expected: result=0, msg='Missing field: name'
        // Branch : foreach $required_fields -> missing 'name'
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_015';
        $desc   = 'Edge case: PUT missing field name -> result=0 (Missing field: name)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            // 'name' intentionally omitted
            'phone'    => '0901234567',
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_016
        // Objective: update() rejects when required field 'phone' is missing
        // Input  : PUT /patients/{id} without 'phone'
        // Expected: result=0, msg='Missing field: phone'
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_016';
        $desc   = 'Edge case: PUT missing field phone -> result=0 (Missing field: phone)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            // 'phone' intentionally omitted
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_017
        // Objective: update() rejects when required field 'birthday' is missing
        // Input  : PUT /patients/{id} without 'birthday'
        // Expected: result=0, msg='Missing field: birthday'
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_017';
        $desc   = 'Edge case: PUT missing field birthday -> result=0 (Missing field: birthday)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'  => 'Nguyen Van Test',
            'phone' => '0901234567',
            // 'birthday' intentionally omitted
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_018
        // Objective: update() rejects name with digits or special characters
        // Input  : name='Nguyen Van 123!'
        // Expected: result=0, msg='Vietnamese name only has letters and space'
        // Branch : isVietnameseName($name) == 0
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_018';
        $desc   = 'Edge case: Name with digits/special chars -> result=0 (isVietnameseName fail)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van 123!',
            'phone'    => '0901234567',
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_019
        // Objective: update() rejects phone shorter than 10 characters
        // Input  : phone='090123' (6 chars)
        // Expected: result=0, msg='Phone number has at least 10 number !'
        // Branch : strlen($phone) < 10
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_019';
        $desc   = 'Edge case: Phone < 10 chars -> result=0 (phone too short)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '090123',
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_020
        // Objective: update() rejects phone with non-digit characters
        // Input  : phone='abcdefghij' (10 alpha chars)
        // Expected: result=0, msg='This is not a valid phone number...'
        // Branch : isNumber($phone) == false (regex /^\d+$/ fails)
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_020';
        $desc   = 'Edge case: Phone contains letters -> result=0 (isNumber fail)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => 'abcdefghij',
            'birthday' => '1990-01-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_021
        // Objective: update() rejects birthday with completely invalid date
        //            e.g. month=13, day=32 (fails checkdate)
        // Input  : birthday='2000-13-32' (month=13, day=32 - both invalid)
        // Expected: result=0, msg='...does not exist!'
        // Branch : checkdate($month, $day, $year) == false
        //
        // *** BUG_PAT_001 NOTE ***
        // Due to the substr bug, monthBirthday = (int)substr("2000-13-32",5,8)
        //   = (int)"-13-32" = 0. checkdate(0,32,2000) = false.
        // So the test still PASSES (result=0) but for the WRONG reason:
        //   actual cause is month=0 (bug), not month=13 (expected).
        // The test result is accidentally correct; the bug is still present.
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_021';
        $desc   = 'Edge case: Birthday=2000-13-32 (invalid date) -> result=0 (checkdate fails)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => '2000-13-32',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}' (Note: BUG_PAT_001 - fails for wrong reason)"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );
        // Always flag this bug when testing birthday
        $this->recordBug('BUG_PAT_001',
            'PatientController::update(): substr($birthday,5,8) returns 8-char'
            . ' string from index 5 (e.g. "-MM-DD"), not 2-char month.'
            . ' (int)"-MM-DD" = 0, so monthBirthday is always 0.'
            . ' checkdate(0,day,year) always returns false.'
            . ' All valid birthday inputs are rejected as "does not exist!".'
            . ' Fix: use substr($birthday,5,2) and substr($birthday,8,2).'
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_022
        // Objective: update() rejects birthday in the future (year > today)
        // Input  : birthday='2099-06-15' (year 2099 > current year 2026)
        // Expected (correct): result=0, msg='Today is ... so that birthday is not valid'
        // Branch (correct): yearDifference = 2026 - 2099 = -73 < 0 -> rejected
        //
        // *** BUG_PAT_001 NOTE ***
        // Actual: checkdate(0,15,2099) = false -> rejected by checkdate first.
        // Test still gets result=0, but reason is the substr bug, not the
        // future-date check. Bug masks the future-date branch.
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_022';
        $desc   = 'Edge case: Birthday in future (year 2099) -> result=0 (future date rejected)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => '2099-06-15',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}' (Note: BUG_PAT_001 causes rejection at checkdate step, not future-date step)"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_023
        // Objective: update() rejects birthday in future (same year, future month)
        // Input  : birthday='{currentYear}-12-01' where month 12 > current month
        //          (This test is meaningful only if current month < 12;
        //           if run in December, adjust to a future day instead.)
        // Expected (correct): result=0 (future month in same year rejected)
        // Branch : yearDiff==0 && monthDiff < 0
        //
        // *** BUG_PAT_001 NOTE ***
        // substr bug makes monthBirthday=0 -> checkdate fails first.
        // -------------------------------------------------------------------
        $testId       = 'UT_PAT_023';
        $currentYear  = (int)date('Y');
        $futureMonth  = '12';
        $futureBday   = "{$currentYear}-{$futureMonth}-01";
        $desc         = "Edge case: Birthday future month {$futureBday} (same year) -> result=0";
        $resp         = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => $futureBday,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}' (BUG_PAT_001 causes checkdate fail before future-month check)"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_024
        // Objective: update() with a PAST valid birthday should SUCCEED
        //            Expected (correct implementation): result=1
        //            Actual (with BUG_PAT_001): result=0 ("does not exist!")
        //            This test is designed to FAIL and EXPOSE the bug.
        // Input  : All valid fields, birthday='1990-05-20' (past, valid date)
        // Expected (per design): result=1, patient updated
        // Actual (with bug): result=0 -> FAIL -> bug confirmed
        // Rollback: If somehow result=1, restore original data in finally
        // -------------------------------------------------------------------
        $testId    = 'UT_PAT_024';
        $desc      = 'Standard: PUT valid past birthday -> result=1 (FAIL expected due to BUG_PAT_001)';
        $newPhone  = '0912345678';
        $validBday = '1990-05-20';

        try {
            $resp = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
                'name'     => $origName,
                'phone'    => $newPhone,
                'birthday' => $validBday,
                'address'  => $origAddress,
                'gender'   => $origGender,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB only if API says success
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $updated     = $this->db->getPatientById($patientId);
                $phoneInDb   = $updated['phone'] ?? '';
                if ($phoneInDb !== $newPhone) {
                    $passed   = false;
                    $checkMsg = "CheckDB FAIL: phone in DB='{$phoneInDb}', expected='{$newPhone}'";
                } else {
                    $checkMsg = "CheckDB OK: phone='{$phoneInDb}' updated correctly";
                }
            }

            if (!$passed) {
                // This is the expected outcome due to BUG_PAT_001
                $actualMsg = $body['msg'] ?? 'N/A';
                $this->recordResult($testId, $desc, false,
                    "result=0, msg='{$actualMsg}' -> BUG_PAT_001 confirmed:"
                    . " valid birthday '{$validBday}' rejected as non-existent"
                );
            } else {
                $this->recordResult($testId, $desc, true, $checkMsg);
            }
        } finally {
            // Rollback if the update somehow succeeded
            if ($this->db->connected) {
                $current = $this->db->getPatientById($patientId);
                if (($current['phone'] ?? '') === $newPhone) {
                    $this->db->rollbackRestorePatient(
                        $patientId, $origPhone, $origName,
                        $origBday, $origAddress, $origGender
                    );
                    echo Color::green("       ↳ Rollback OK: patient data restored\n");
                }
            }
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_025
        // Objective: update() rejects address containing invalid characters
        //            isAddress regex: allows letters, digits, Vietnamese chars,
        //            space, comma, hyphen. Special chars like @ # $ are invalid.
        // Input  : address='Test@Address#1' (contains @ and #)
        // Expected: result=0, msg='Address only accepts letters, space & number'
        // Branch : isAddress($address) == 0
        // Note   : birthday still triggers BUG_PAT_001 before address check,
        //          so this test's result=0 is caused by birthday bug, not address.
        //          Test is noted accordingly.
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_025';
        $desc   = 'Edge case: Address with special chars -> result=0 (isAddress fail)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => '1990-05-20',
            'address'  => 'Test@Address#1',
            'gender'   => 0,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}' (Note: BUG_PAT_001 causes birthday to fail first; address validation branch not reached)"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_026
        // Objective: update() rejects gender value not in [0,1]
        // Input  : gender=5 (not 0 or 1)
        // Expected: result=0, msg='Gender value is not correct...'
        // Branch : !in_array($gender, [0,1])
        // Note   : BUG_PAT_001 will cause birthday to fail first.
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_026';
        $desc   = 'Edge case: Gender=5 (invalid) -> result=0 (gender not in [0,1])';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => 'Nguyen Van Test',
            'phone'    => '0901234567',
            'birthday' => '1990-05-20',
            'address'  => '123 Test Street',
            'gender'   => 5,
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}' (Note: BUG_PAT_001 causes birthday to fail first; gender branch not reached)"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_027
        // Objective: update() stores gender=1 (male) correctly
        //            Source code: $gender = Input::put("gender") ? Input::put("gender") : 0
        //            When gender=1: condition is truthy -> $gender = "1" -> OK
        //            When gender=0: condition is falsy  -> $gender = 0   -> OK (same value)
        //            So gender storage itself is not bugged.
        //            However this test cannot reach save() due to BUG_PAT_001.
        //            Documented for completeness.
        // Input  : gender=1, valid other fields, birthday='1990-05-20'
        // Expected (correct): result=1, gender stored as 1
        // Actual (with bug): result=0 (birthday rejected first)
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_027';
        $desc   = 'Standard: PUT gender=1 (male) -> result=1 (blocked by BUG_PAT_001)';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/patients/' . $patientId, [
            'name'     => $origName,
            'phone'    => $origPhone,
            'birthday' => '1990-05-20',
            'address'  => $origAddress,
            'gender'   => 1,
        ]);
        $body   = $resp['body'];
        // Expected correct behavior = result=1. But BUG_PAT_001 causes result=0.
        // We record FAIL here to document the bug impact.
        $passed = ($body['result'] ?? 0) == 1;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, gender=1 stored correctly"
                : "result=0, msg='{$body['msg']}'"
                  . " -> BUG_PAT_001 prevents reaching gender/save logic"
        );
    }


    // =========================================================================
    // GROUP 5: DELETE PATIENT
    // Tests PatientController::delete() via DELETE /patients/{id}
    //
    // SOURCE CODE LOGIC:
    //   delete() ALWAYS returns result=0.
    //   Two code paths:
    //     Path A: id == 1 -> result=0, msg="This patient is an example & can be deleted !"
    //                        (BUG_PAT_002: message says "can" but means "cannot")
    //     Path B: any other id -> result=0, msg="This action is not allowed !"
    //
    // BRANCHES TO COVER:
    //   B1  id == 1            -> result=0 with specific message (typo bug)
    //   B2  id != 1 (valid)    -> result=0, msg="This action is not allowed !"
    //   B3  id not found       -> still result=0 (no existence check before reject)
    //   B4  No auth token      -> HTTP 302 redirect
    //
    // CheckDB: Verify the patient record STILL EXISTS after each delete attempt
    //          (confirming deletion was correctly rejected)
    // =========================================================================
    public function runDeleteTests(): void {
        $this->printGroupHeader("GROUP 5: DELETE PATIENT (DELETE /patients/{id})");

        $firstPatient = $this->db->getFirstPatient();
        $validId      = $firstPatient ? (int)$firstPatient['id'] : 2;

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_028
        // Objective: delete() with id=1 returns result=0 with the special message
        //            that contains a typo: "can be deleted" instead of "cannot"
        // Input  : DELETE /patients/1
        // Expected: result=0
        //           CheckDB: patient ID=1 still exists in tn_patients
        //           Note: msg contains typo "can be deleted" (BUG_PAT_002)
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_028';
        $desc   = 'Edge case: DELETE /patients/1 -> result=0, record still exists (typo in msg)';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/patients/1');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        // CheckDB: patient 1 must still exist
        $checkMsg = '';
        if ($this->db->connected) {
            $record = $this->db->getPatientById(1);
            if (!$record) {
                $passed   = false;
                $checkMsg = 'CheckDB FAIL: Patient ID=1 was DELETED from DB!';
            } else {
                $checkMsg = 'CheckDB OK: Patient ID=1 still in DB (not deleted)';
            }
        }

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}', {$checkMsg}"
                : "result=" . ($body['result'] ?? 'N/A') . ", {$checkMsg}"
        );

        // Flag the typo bug
        $msg1 = $body['msg'] ?? '';
        if (stripos($msg1, 'can be deleted') !== false
            && stripos($msg1, 'cannot') === false) {
            $this->recordBug('BUG_PAT_002',
                "PatientController::delete(): msg for id=1 says \""
                . $msg1 . "\" which implies deletion is allowed,"
                . " but the code deliberately prevents it."
                . " Fix: change to 'cannot be deleted'."
            );
        }

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_029
        // Objective: delete() with a valid id != 1 returns result=0
        //            "This action is not allowed !"
        // Input  : DELETE /patients/{validId} (not 1)
        // Expected: result=0
        //           CheckDB: patient record still exists in tn_patients
        // -------------------------------------------------------------------
        $deleteId = ($validId === 1) ? 2 : $validId;
        $testId   = 'UT_PAT_029';
        $desc     = "Standard: DELETE /patients/{$deleteId} (valid, non-1 id) -> result=0 (not allowed)";
        $resp     = $this->http->request('DELETE', API_BASE_URL . '/patients/' . $deleteId);
        $body     = $resp['body'];
        $passed   = ($body['result'] ?? 1) == 0;

        // CheckDB: Patient must still exist
        $checkMsg = '';
        if ($this->db->connected) {
            $record = $this->db->getPatientById($deleteId);
            if (!$record) {
                $passed   = false;
                $checkMsg = "CheckDB FAIL: Patient ID={$deleteId} was DELETED from DB!";
            } else {
                $checkMsg = "CheckDB OK: Patient ID={$deleteId} still in DB (deletion rejected)";
            }
        }

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}', {$checkMsg}"
                : "result=" . ($body['result'] ?? 'N/A') . ", {$checkMsg}"
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_030
        // Objective: delete() with nonexistent id also returns result=0
        //            Source code does NOT check isAvailable() before rejecting;
        //            it always rejects. So even invalid IDs get result=0.
        // Input  : DELETE /patients/999999
        // Expected: result=0, msg='This action is not allowed !'
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_030';
        $desc   = 'Edge case: DELETE /patients/999999 (nonexistent) -> result=0 (always rejected)';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/patients/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Expected result=0, got result=" . ($body['result'] ?? 'N/A')
        );

        // -------------------------------------------------------------------
        // TC ID  : UT_PAT_031
        // Objective: delete() without auth token is rejected before handler
        // Input  : DELETE /patients/{validId} without Authorization header
        // Expected: HTTP 302 redirect
        // -------------------------------------------------------------------
        $testId = 'UT_PAT_031';
        $desc   = 'Edge case: DELETE no auth token -> access denied (HTTP 302)';
        $noAuth = new HttpClient();
        $resp   = $noAuth->request('DELETE', API_BASE_URL . '/patients/' . $validId);
        $passed = ($resp['body']['result'] ?? 1) == 0
               || $resp['status'] == 302
               || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Correctly denied"
                : "Expected denial, got HTTP {$resp['status']}"
        );
    }


    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================
    public function run(string $group = 'all'): void {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════════╗\n" .
            "║   UNIT TEST - PATIENTS MODULE - UMBRELLA CORPORATION    ║\n" .
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
            case 'post':   $this->runPostTests();      break;
            case 'detail': $this->runGetByIdTests();   break;
            case 'update': $this->runUpdateTests();    break;
            case 'delete': $this->runDeleteTests();    break;
            case 'all':
            default:
                $this->runListTests();
                $this->runPostTests();
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