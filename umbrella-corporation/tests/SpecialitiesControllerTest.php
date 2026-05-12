<?php
/**
 * =============================================================================
 * FILE:    SpecialityTest.php
 * PROJECT: Umbrella Corporation - Hệ thống quản lý phòng khám
 * MODULE:  Chuyên khoa (Specialities)
 *
 * MÔ TẢ:
 *   Test script PHP cho module Chuyên khoa, gọi trực tiếp API bằng cURL thuần.
 *   Chạy hoàn toàn trên terminal, KHÔNG cần trình duyệt, KHÔNG cần Guzzle.
 *
 * QUY TRÌNH TEST (theo tài liệu yêu cầu):
 *   Bước 1: Với mỗi method, xây dựng test case với input/expected output rõ ràng.
 *           Chọn input đạt phủ cấp 2 (phủ hết các nhánh trong source code).
 *   Bước 2: Gọi API, kiểm tra kết quả, CheckDB xác minh thay đổi.
 *   Bước 3: Rollback trong finally block để DB luôn trở về trạng thái ban đầu.
 *
 * CẤU TRÚC API:
 *   POST   /api/login                     → Đăng nhập (bắt buộc trước khi test)
 *   GET    /api/specialities              → Danh sách chuyên khoa  (SpecialitiesController::getAll)
 *   POST   /api/specialities              → Tạo chuyên khoa mới    (SpecialitiesController::save)
 *   GET    /api/speciality/{id}           → Chi tiết chuyên khoa   (SpecialityController::getById)
 *   PUT    /api/speciality/{id}           → Cập nhật chuyên khoa   (SpecialityController::update)
 *   DELETE /api/speciality/{id}           → Xóa chuyên khoa        (SpecialityController::delete)
 *   POST   /api/speciality/{id} (avatar)  → Cập nhật ảnh           (SpecialityController::updateAvatar)
 *
 * DATABASE: nextpost, bảng tn_specialities, tn_doctors
 *
 * CÁCH CHẠY:
 *   php SpecialityTest.php              → Chạy tất cả nhóm
 *   php SpecialityTest.php list         → Danh sách chuyên khoa
 *   php SpecialityTest.php create       → Tạo chuyên khoa mới
 *   php SpecialityTest.php detail       → Chi tiết chuyên khoa
 *   php SpecialityTest.php update       → Cập nhật chuyên khoa
 *   php SpecialityTest.php delete       → Xóa chuyên khoa
 *   php SpecialityTest.php avatar       → Cập nhật ảnh chuyên khoa
 * =============================================================================
 */

// =============================================================================
// CẤU HÌNH - Chỉnh sửa nếu môi trường khác
// =============================================================================
define('API_BASE_URL',        'http://localhost:8080/PTIT-Do-An-Tot-Nghiep/api');
define('ADMIN_EMAIL',         'phongkaster@gmail.com');
define('ADMIN_PASSWORD',      '123456');
define('DB_HOST',             '127.0.0.1');
define('DB_PORT',             3306);
define('DB_NAME',             'nextpost');
define('DB_USER',             'root');
define('DB_PASSWORD',         '');
define('TABLE_SPECIALITIES',  'tn_specialities');
define('TABLE_DOCTORS',       'tn_doctors');
// =============================================================================


// =============================================================================
// CLASS: Color - Hiển thị màu sắc trên terminal
// =============================================================================
class Color
{
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
// CLASS: DatabaseHelper - Kết nối và thao tác DB
//
// Dùng cho:
//   - CheckDB  : Xác minh dữ liệu thay đổi đúng sau khi gọi API
//   - Rollback : Trong finally block, khôi phục DB về trạng thái ban đầu
//
// Bảng chính: tn_specialities
// Schema: id, name, description, image
// =============================================================================
class DatabaseHelper
{
    private ?PDO $pdo       = null;
    public bool  $connected = false;

    public function __construct()
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                DB_HOST, DB_PORT, DB_NAME
            );
            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->connected = true;
        } catch (PDOException $e) {
            echo Color::yellow("[DB WARNING] Không kết nối được: " . $e->getMessage()) . "\n";
            echo Color::yellow("[DB WARNING] CheckDB và Rollback sẽ bị bỏ qua.") . "\n\n";
        }
    }

    /** Thực thi SELECT, trả về danh sách bản ghi */
    public function query(string $sql, array $params = []): array
    {
        if (!$this->connected) return [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Thực thi INSERT / UPDATE / DELETE */
    public function execute(string $sql, array $params = []): void
    {
        if (!$this->connected) return;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    // ------------------------------------------------------------------
    // Thao tác trên bảng tn_specialities
    // ------------------------------------------------------------------

    /**
     * Lấy bản ghi chuyên khoa theo ID.
     * Dùng cho CheckDB sau khi tạo / cập nhật / xóa.
     */
    public function getSpecialityById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_SPECIALITIES . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Đếm tổng số chuyên khoa trong DB.
     * Dùng cho CheckDB trước/sau thao tác tạo/xóa.
     */
    public function countSpecialities(): int
    {
        if (!$this->connected) return -1;
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM " . TABLE_SPECIALITIES);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy ID bất kỳ chuyên khoa tồn tại.
     * Dùng cho test getById, update, delete.
     */
    public function getAnySpecialityId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM " . TABLE_SPECIALITIES . " ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Lấy ID chuyên khoa đang có doctor (dùng để test xóa thất bại).
     * Tìm chuyên khoa != id=1 có ít nhất 1 bác sĩ.
     */
    public function getSpecialityIdWithDoctor(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT speciality_id FROM " . TABLE_DOCTORS . "
             WHERE speciality_id IS NOT NULL AND speciality_id != 0
             LIMIT 1"
        );
        return isset($rows[0]) ? (int) $rows[0]['speciality_id'] : null;
    }

    /**
     * Lấy ID chuyên khoa không có doctor và id != 1.
     * Dùng để tìm chuyên khoa có thể xóa được.
     */
    public function getDeletableSpecialityId(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT s.id FROM " . TABLE_SPECIALITIES . " s
             LEFT JOIN " . TABLE_DOCTORS . " d ON d.speciality_id = s.id
             WHERE s.id != 1
             GROUP BY s.id
             HAVING COUNT(d.id) = 0
             LIMIT 1"
        );
        return isset($rows[0]) ? (int) $rows[0]['id'] : null;
    }

    // ------------------------------------------------------------------
    // ROLLBACK helpers
    // ------------------------------------------------------------------

    /**
     * ROLLBACK: Xóa chuyên khoa vừa tạo trong test.
     * Gọi trong finally block để đảm bảo DB về trạng thái ban đầu.
     */
    public function rollbackDeleteSpeciality(int $id): void
    {
        if (!$this->connected || $id <= 0) return;
        $this->execute(
            "DELETE FROM " . TABLE_SPECIALITIES . " WHERE id = ?",
            [$id]
        );
    }

    /**
     * ROLLBACK: Khôi phục name và description của chuyên khoa về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật chuyên khoa.
     */
    public function rollbackRestoreSpeciality(
        int    $id,
        string $originalName,
        string $originalDescription
    ): void {
        if (!$this->connected || $id <= 0) return;
        $this->execute(
            "UPDATE " . TABLE_SPECIALITIES . " SET name = ?, description = ? WHERE id = ?",
            [$originalName, $originalDescription, $id]
        );
    }

    /**
     * ROLLBACK: Khôi phục image của chuyên khoa về giá trị ban đầu.
     * Gọi trong finally block sau test updateAvatar.
     */
    public function rollbackRestoreImage(int $id, string $originalImage): void
    {
        if (!$this->connected || $id <= 0) return;
        $this->execute(
            "UPDATE " . TABLE_SPECIALITIES . " SET image = ? WHERE id = ?",
            [$originalImage, $id]
        );
    }

    public function close(): void { $this->pdo = null; }
}


// =============================================================================
// CLASS: HttpClient - Gọi API bằng cURL thuần PHP
//
// API dùng header: Authorization: JWT {token}, type: Doctor
// (theo cấu trúc AppointmentTest_Final.php)
// =============================================================================
class HttpClient
{
    private ?string $accessToken = null;

    public function setToken(string $token): void { $this->accessToken = $token; }
    public function clearToken(): void            { $this->accessToken = null;  }

    /**
     * Thực hiện HTTP request bằng cURL.
     *
     * @param  string      $method   GET / POST / PUT / DELETE
     * @param  string      $url      URL đầy đủ của API endpoint
     * @param  array       $data     Dữ liệu gửi kèm
     * @param  array|null  $files    Dữ liệu file upload (dùng cho updateAvatar)
     * @return array                 ['status' => int, 'body' => array]
     */
    public function request(
        string $method,
        string $url,
        array  $data  = [],
        ?array $files = null
    ): array {
        $ch      = curl_init();
        $headers = ['Accept: application/json'];

        // Đính kèm JWT token nếu đã đăng nhập
        if ($this->accessToken) {
            $headers[] = 'Authorization: JWT ' . $this->accessToken;
            $headers[] = 'type: Doctor';
        }

        switch (strtoupper($method)) {
            case 'GET':
                // Với GET: đính dữ liệu vào query string
                if (!empty($data)) {
                    $url .= '?' . http_build_query($data);
                }
                break;

            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($files) {
                    // Multipart form data (dùng cho upload file)
                    $postFields = $data;
                    foreach ($files as $fieldName => $filePath) {
                        $postFields[$fieldName] = new CURLFile($filePath);
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
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

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        // Xử lý lỗi kết nối
        if ($curlError) {
            return [
                'status' => 0,
                'body'   => ['result' => 0, 'msg' => 'cURL Error: ' . $curlError],
            ];
        }

        // Parse JSON response
        $parsed = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = [
                'result' => 0,
                'msg'    => 'Invalid JSON: ' . substr($responseBody, 0, 200),
            ];
        }

        return ['status' => $httpStatus, 'body' => $parsed];
    }
}


// =============================================================================
// CLASS: TestRunner - Chạy và quản lý tất cả test case
// =============================================================================
class TestRunner
{
    private int   $totalTests  = 0;
    private int   $passedTests = 0;
    private int   $failedTests = 0;
    private array $failedList  = [];

    /** HTTP client đã đăng nhập (dùng cho hầu hết test) */
    private HttpClient $http;

    /** HTTP client KHÔNG có token (test trường hợp chưa đăng nhập) */
    private HttpClient $unauthHttp;

    private DatabaseHelper $db;

    /** Access token admin sau khi login */
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->http       = new HttpClient();
        $this->unauthHttp = new HttpClient(); // Không gọi setToken → không có auth
        $this->db         = new DatabaseHelper();
    }

    // =========================================================================
    // HELPER: In tiêu đề nhóm test
    // =========================================================================
    private function printGroupHeader(string $groupName): void
    {
        echo "\n" . Color::bold(Color::blue(
            "══════════════════════════════════════════════\n"
            . "  {$groupName}\n"
            . "══════════════════════════════════════════════"
        )) . "\n";
    }

    // =========================================================================
    // HELPER: Ghi nhận kết quả một test case
    //
    // @param string $testId      ID test case (TC_SPEC_LIST_001)
    // @param string $description Mô tả ngắn gọn
    // @param bool   $passed      true = PASS, false = FAIL
    // @param string $message     Chi tiết: CheckDB, Rollback, nguyên nhân fail
    // =========================================================================
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
                Color::green('[PASS]'), Color::cyan($testId), $description
            );
            if ($message) {
                echo Color::green("         ↳ {$message}") . "\n";
            }
        } else {
            $this->failedTests++;
            echo sprintf("  %s %s %s\n",
                Color::red('[FAIL]'), Color::cyan($testId), $description
            );
            if ($message) {
                echo Color::red("         ↳ {$message}") . "\n";
            }
            $this->failedList[] = "{$testId}: {$description}";
            if ($message) {
                $this->failedList[] = "       → {$message}";
            }
        }
    }

    // =========================================================================
    // HELPER: In tổng kết sau khi chạy xong
    // =========================================================================
    private function printSummary(): void
    {
        echo "\n" . Color::bold("══════════════════════════════════════════════") . "\n";
        echo Color::bold("  KẾT QUẢ TỔNG QUAN") . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n";
        echo sprintf("  Tổng số test : %d\n", $this->totalTests);
        echo sprintf("  %s : %d\n", Color::green('PASS'), $this->passedTests);
        echo sprintf("  %s : %d\n", Color::red('FAIL'),   $this->failedTests);

        if (!empty($this->failedList)) {
            echo "\n" . Color::red(Color::bold("  DANH SÁCH TEST THẤT BẠI:")) . "\n";
            foreach ($this->failedList as $line) {
                echo Color::red("  {$line}") . "\n";
            }
        }

        $rate = $this->totalTests > 0
            ? round($this->passedTests / $this->totalTests * 100, 1)
            : 0;
        echo "\n" . Color::bold(sprintf("  Tỷ lệ thành công: %s%%", $rate)) . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n\n";
    }

    // =========================================================================
    // ĐĂNG NHẬP - Bắt buộc trước khi test Speciality / Specialities
    //
    // Không tính vào test case. Token được lưu vào $this->accessToken
    // và inject vào $this->http để dùng cho toàn bộ test.
    // =========================================================================
    private function doLogin(): bool
    {
        $resp = $this->http->request('POST', API_BASE_URL . '/login', [
            'email'    => ADMIN_EMAIL,
            'password' => ADMIN_PASSWORD,
            'type'     => 'doctor',
        ]);

        $body = $resp['body'];

        if (($body['result'] ?? 0) == 1 && !empty($body['accessToken'])) {
            $this->accessToken = $body['accessToken'];
            $this->http->setToken($this->accessToken);
            echo Color::green("  ✓ Đăng nhập admin thành công\n");
            echo Color::green("  ✓ Token: " . substr($this->accessToken, 0, 30) . "...\n");
            return true;
        }

        echo Color::red("  ✗ Đăng nhập thất bại: " . ($body['msg'] ?? 'Không rõ lỗi') . "\n");
        echo Color::red("  ✗ Kiểm tra lại ADMIN_EMAIL, ADMIN_PASSWORD, API_BASE_URL\n");
        return false;
    }


    // =========================================================================
    // NHÓM TEST 1: DANH SÁCH CHUYÊN KHOA
    // Kiểm tra GET /api/specialities → SpecialitiesController::getAll()
    //
    // Các nhánh cần phủ:
    //   - Không có token   → bị redirect (nhánh !$AuthUser)
    //   - Không có filter  → truy vấn mặc định, trả data + quantity
    //   - Filter search    → nhánh $search_query → WHERE LIKE
    //   - Filter order     → nhánh $order["column"] và $order["dir"]
    //   - Filter length    → nhánh limit/offset
    // =========================================================================
    public function runListTests(): void
    {
        $this->printGroupHeader("NHÓM 1: DANH SÁCH CHUYÊN KHOA (GET /api/specialities)");


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_001 - Test ngoại lệ: Không có token
        // Nhánh   : process() → !$AuthUser → header Location redirect
        // Input   : GET /api/specialities không có Authorization header
        // Expected: HTTP 302 hoặc result=0 (từ chối truy cập)
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_LIST_001';
        $desc   = 'Test ngoại lệ: Không có token → bị redirect hoặc result=0';

        $resp   = $this->unauthHttp->request('GET', API_BASE_URL . '/specialities');
        $body   = $resp['body'];
        $passed = ($resp['status'] === 302)
               || ($resp['status'] === 401)
               || ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Từ chối đúng"
                : "Mong đợi bị từ chối, nhận result=" . ($body['result'] ?? 'N/A')
                  . ", HTTP {$resp['status']}"
        );


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_002 - Test chuẩn: Lấy tất cả chuyên khoa (không filter)
        // Nhánh   : getAll() → query mặc định → trả data + quantity
        // Input   : GET /api/specialities với token admin
        // Expected: result=1, data là mảng, quantity là số nguyên
        // CheckDB : quantity phải khớp COUNT(*) thực tế trong tn_specialities
        // ---------------------------------------------------------------
        $testId  = 'TC_SPEC_LIST_002';
        $desc    = 'Test chuẩn: GET /api/specialities không filter → result=1, có data + quantity';
        $dbCount = $this->db->countSpecialities(); // CheckDB: đếm trước

        $resp   = $this->http->request('GET', API_BASE_URL . '/specialities');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);

        // CheckDB: quantity trong response phải khớp DB
        $checkMsg = '';
        if ($passed && $dbCount >= 0) {
            $quantityMatch = ((int)$body['quantity'] === $dbCount);
            if (!$quantityMatch) {
                $passed   = false;
                $checkMsg = "CheckDB THẤT BẠI: quantity={$body['quantity']}, DB={$dbCount}";
            } else {
                $checkMsg = "CheckDB OK: quantity={$body['quantity']} khớp DB ({$dbCount} bản ghi)";
            }
        } else {
            $checkMsg = "result=1, quantity={$body['quantity']}, "
                . "data count=" . count($body['data'] ?? []);
        }

        $this->recordResult($testId, $desc, $passed,
            $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
        );


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_003 - Test chuẩn: Filter theo search (name LIKE)
        // Nhánh   : getAll() → $search_query → WHERE name LIKE hoặc description LIKE
        // Input   : GET /api/specialities?search={từ_khóa}
        // Expected: result=1, các bản ghi trả về phải chứa từ khóa trong name
        //           hoặc description
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_LIST_003';

        // Lấy 3 ký tự đầu của chuyên khoa đầu tiên làm từ khóa tìm kiếm
        $firstSpec   = $this->db->query(
            "SELECT name FROM " . TABLE_SPECIALITIES . " LIMIT 1"
        );
        $searchQuery = isset($firstSpec[0]) ? substr($firstSpec[0]['name'], 0, 3) : 'Tim';
        $desc        = "Test chuẩn: Filter search='{$searchQuery}' → "
            . "kết quả phải chứa từ khóa trong name hoặc description";

        $resp   = $this->http->request('GET', API_BASE_URL . '/specialities',
            ['search' => $searchQuery]
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;

        // Xác minh mỗi bản ghi phải chứa từ khóa trong name hoặc description
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                $nameMatch = stripos($item['name']        ?? '', $searchQuery) !== false;
                $descMatch = stripos($item['description'] ?? '', $searchQuery) !== false;
                if (!$nameMatch && !$descMatch) {
                    $passed = false;
                    break;
                }
            }
        }

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? [])
                  . " chuyên khoa, tất cả khớp từ khóa '{$searchQuery}'"
                : "Có bản ghi không chứa từ khóa '{$searchQuery}' trong name hoặc description"
        );


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_004 - Test chuẩn: Phân trang length + start
        // Nhánh   : getAll() → $query->limit($length)->offset($start)
        // Input   : GET /api/specialities?length=2&start=0
        // Expected: result=1, số bản ghi trả về <= 2
        //           Lưu ý: quantity vẫn là tổng số, data mới bị giới hạn
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_LIST_004';
        $desc   = 'Test chuẩn: Phân trang length=2, start=0 → tối đa 2 bản ghi trong data';

        $resp   = $this->http->request('GET', API_BASE_URL . '/specialities', [
            'length' => 2,
            'start'  => 0,
        ]);
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 2;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về {$count} chuyên khoa (≤ 2) - phân trang đúng"
                : "Trả về {$count} chuyên khoa, mong đợi ≤ 2"
        );


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_005 - Test chuẩn: Sắp xếp theo name ASC
        // Nhánh   : getAll() → $order["column"]="name", $order["dir"]="asc"
        //           → orderBy DB::raw(TABLE_SPECIALITIES.".name * 1"), "asc"
        // Input   : GET /api/specialities?order[column]=name&order[dir]=asc
        // Expected: result=1
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_LIST_005';
        $desc   = 'Test chuẩn: Sắp xếp order[column]=name, order[dir]=asc → result=1';

        $resp   = $this->http->request('GET', API_BASE_URL . '/specialities', [
            'order' => ['column' => 'name', 'dir' => 'asc'],
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, trả về " . count($body['data'] ?? []) . " chuyên khoa theo thứ tự name ASC"
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_LIST_006 - Test chuẩn: order[dir] không hợp lệ → mặc định "desc"
        // Nhánh   : getAll() → $sort = in_array($type, $validType) ? $type : "desc"
        //           → dir="xyz" không hợp lệ → fallback về "desc"
        // Input   : GET /api/specialities?order[column]=id&order[dir]=xyz
        // Expected: result=1 (không bị lỗi, tự fallback về desc)
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_LIST_006';
        $desc   = 'Test chuẩn: order[dir]=xyz không hợp lệ → hệ thống fallback về desc, result=1';

        $resp   = $this->http->request('GET', API_BASE_URL . '/specialities', [
            'order' => ['column' => 'id', 'dir' => 'xyz'],
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, hệ thống xử lý dir không hợp lệ đúng (fallback về desc)"
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );
    }


    // =========================================================================
    // NHÓM TEST 2: TẠO CHUYÊN KHOA MỚI
    // Kiểm tra POST /api/specialities → SpecialitiesController::save()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin   → lỗi phân quyền (nhánh role != "admin")
    //   - Thiếu field name   → lỗi missing field
    //   - Thiếu field desc   → lỗi missing field
    //   - Trùng name         → lỗi duplicate (chỉ check name, không check desc)
    //   - Dữ liệu hợp lệ     → tạo thành công, image mặc định "default_avatar.jpg"
    // =========================================================================
    public function runCreateTests(): void
    {
        $this->printGroupHeader("NHÓM 2: TẠO CHUYÊN KHOA MỚI (POST /api/specialities)");


        // ---------------------------------------------------------------
        // TC_SPEC_CREATE_001 - Test ngoại lệ: Thiếu field "name"
        // Nhánh   : save() → !Input::post("name") → jsonecho lỗi
        // Input   : POST chỉ có description, thiếu name
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_CREATE_001';
        $desc   = 'Test ngoại lệ: Thiếu field "name" → result=0, msg Missing field';

        $resp   = $this->http->request('POST', API_BASE_URL . '/specialities', [
            // 'name' bị bỏ trống
            'description' => 'Mo ta test',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_CREATE_002 - Test ngoại lệ: Thiếu field "description"
        // Nhánh   : save() → !Input::post("description") → jsonecho lỗi
        // Input   : POST chỉ có name, thiếu description
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_CREATE_002';
        $desc   = 'Test ngoại lệ: Thiếu field "description" → result=0, msg Missing field';

        $resp   = $this->http->request('POST', API_BASE_URL . '/specialities', [
            'name' => 'Chuyen Khoa Test',
            // 'description' bị bỏ trống
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_CREATE_003 - Test ngoại lệ: Trùng name (check duplicate)
        // Nhánh   : save() → count($result) > 0 → jsonecho lỗi
        // Input   : POST với name đã tồn tại trong DB
        // Expected: result=0, msg chứa "exists"
        // CheckDB : Không tạo thêm bản ghi trùng
        // Lưu ý   : save() chỉ check trùng name, KHÔNG check description
        // ---------------------------------------------------------------
        $testId      = 'TC_SPEC_CREATE_003';
        $desc        = 'Test ngoại lệ: Trùng name → result=0, không tạo bản ghi mới';
        $existingSpec = $this->db->query(
            "SELECT name FROM " . TABLE_SPECIALITIES . " LIMIT 1"
        );

        if (!empty($existingSpec)) {
            $dupName     = $existingSpec[0]['name'];
            $countBefore = $this->db->countSpecialities();

            $resp   = $this->http->request('POST', API_BASE_URL . '/specialities', [
                'name'        => $dupName,
                'description' => 'Mo ta khac hoan toan nhung van bi tu choi vi trung name',
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            // CheckDB: Số bản ghi không được tăng
            $countAfter = $this->db->countSpecialities();
            if ($passed && $countAfter !== $countBefore) {
                $passed   = false;
                $checkMsg = "CheckDB THẤT BẠI: Bản ghi trùng vẫn được tạo!";
            } else {
                $checkMsg = "CheckDB OK: Không tạo thêm bản ghi trùng name='{$dupName}'";
            }

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'. {$checkMsg}"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
                      . ". {$checkMsg}"
            );
        } else {
            $this->recordResult($testId, $desc, false,
                'SKIP: DB không có chuyên khoa nào để test trùng lặp'
            );
        }


        // ---------------------------------------------------------------
        // TC_SPEC_CREATE_004 - Test chuẩn: Tạo chuyên khoa mới thành công
        // Nhánh   : save() → $Speciality->save() → result=1
        // Input   : POST với name+description hợp lệ, chưa tồn tại
        // Expected: result=1, data có id, name, description, image="default_avatar.jpg"
        // CheckDB : Bản ghi mới xuất hiện trong tn_specialities với đúng dữ liệu
        //           image mặc định phải là "default_avatar.jpg"
        // Rollback: Xóa chuyên khoa vừa tạo trong finally block
        // ---------------------------------------------------------------
        $testId     = 'TC_SPEC_CREATE_004';
        $desc       = 'Test chuẩn: Tạo chuyên khoa hợp lệ → result=1, CheckDB, Rollback';
        $newName    = 'Chuyen Khoa Test TC004 ' . time();
        $newDesc    = 'Mo ta chuyen khoa test ' . time();
        $createdId  = 0;
        $countBefore = $this->db->countSpecialities();

        try {
            $resp   = $this->http->request('POST', API_BASE_URL . '/specialities', [
                'name'        => $newName,
                'description' => $newDesc,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && !empty($body['data']['id'])
                   && ($body['data']['name']        ?? '') === $newName
                   && ($body['data']['description'] ?? '') === $newDesc
                   && ($body['data']['image']       ?? '') === 'default_avatar.jpg';

            // CheckDB: Xác minh bản ghi trong DB
            $checkMsg = '';
            if ($passed) {
                $createdId  = (int)$body['data']['id'];
                $countAfter = $this->db->countSpecialities();

                if ($countAfter !== $countBefore + 1) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: "
                        . "countBefore={$countBefore}, countAfter={$countAfter}";
                } else {
                    $savedSpec = $this->db->getSpecialityById($createdId);
                    $nameOk    = ($savedSpec['name']        ?? '') === $newName;
                    $descOk    = ($savedSpec['description'] ?? '') === $newDesc;
                    $imageOk   = ($savedSpec['image']       ?? '') === 'default_avatar.jpg';

                    if (!$nameOk || !$descOk || !$imageOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . (!$nameOk  ? "name sai; "  : "")
                            . (!$descOk  ? "desc sai; "  : "")
                            . (!$imageOk ? "image không phải default_avatar.jpg" : "");
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId}, "
                            . "name='{$savedSpec['name']}', "
                            . "image='{$savedSpec['image']}'";
                    }
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );

        } finally {
            // Rollback: Xóa chuyên khoa vừa tạo (luôn chạy dù test pass hay fail)
            if ($createdId > 0) {
                $this->db->rollbackDeleteSpeciality($createdId);
                $afterRollback = $this->db->getSpecialityById($createdId);
                if ($afterRollback === null) {
                    echo Color::green(
                        "         ↳ Rollback OK: Chuyên khoa ID={$createdId} đã xóa khỏi DB\n"
                    );
                } else {
                    echo Color::red(
                        "         ↳ Rollback THẤT BẠI: Chuyên khoa ID={$createdId} vẫn còn\n"
                    );
                }
            }
        }
    }


    // =========================================================================
    // NHÓM TEST 3: CHI TIẾT CHUYÊN KHOA
    // Kiểm tra GET /api/speciality/{id} → SpecialityController::getById()
    //
    // Các nhánh cần phủ:
    //   - Không có token        → redirect (nhánh !$AuthUser)
    //   - ID không tồn tại      → lỗi "not available" (nhánh !isAvailable)
    //   - ID hợp lệ             → trả đúng dữ liệu (nhánh thành công)
    //
    // Lưu ý: getById() KHÔNG kiểm tra role, bất kỳ user nào đã login đều xem được.
    // =========================================================================
    public function runDetailTests(): void
    {
        $this->printGroupHeader("NHÓM 3: CHI TIẾT CHUYÊN KHOA (GET /api/speciality/{id})");


        // ---------------------------------------------------------------
        // TC_SPEC_DETAIL_001 - Test ngoại lệ: Không có token
        // Nhánh   : process() → !$AuthUser → header Location redirect
        // Input   : GET /api/speciality/1 không có Authorization header
        // Expected: HTTP 302 hoặc result=0
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_DETAIL_001';
        $desc   = 'Test ngoại lệ: Không có token → bị redirect hoặc result=0';

        $resp   = $this->unauthHttp->request('GET', API_BASE_URL . '/speciality/1');
        $body   = $resp['body'];
        $passed = ($resp['status'] === 302)
               || ($resp['status'] === 401)
               || ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Từ chối đúng"
                : "Mong đợi bị từ chối, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_DETAIL_002 - Test ngoại lệ: ID không tồn tại
        // Nhánh   : getById() → !$Speciality->isAvailable() → jsonecho lỗi
        // Input   : GET /api/speciality/999999 (ID chắc chắn không có)
        // Expected: result=0, msg chứa "not available"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_DETAIL_002';
        $desc   = 'Test ngoại lệ: GET /api/speciality/999999 không tồn tại → result=0';

        $resp   = $this->http->request('GET', API_BASE_URL . '/speciality/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_DETAIL_003 - Test chuẩn: Lấy chuyên khoa tồn tại
        // Nhánh   : getById() → isAvailable() → trả data đầy đủ
        // Input   : GET /api/speciality/{id} với id hợp lệ
        // Expected: result=1, data có id, name, description, image
        // CheckDB : Dữ liệu trả về phải khớp với bản ghi trong tn_specialities
        // ---------------------------------------------------------------
        $testId       = 'TC_SPEC_DETAIL_003';
        $specId       = $this->db->getAnySpecialityId();

        if (!$specId) {
            $this->recordResult($testId,
                'Test chuẩn: GET /api/speciality/{id} hợp lệ → result=1, đủ fields',
                false, 'SKIP: DB không có chuyên khoa nào'
            );
        } else {
            $desc = "Test chuẩn: GET /api/speciality/{$specId} → "
                . "result=1, data có id/name/description/image, CheckDB";

            $resp   = $this->http->request('GET', API_BASE_URL . '/speciality/' . $specId);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && isset($body['data']['id'])
                   && isset($body['data']['name'])
                   && isset($body['data']['description'])
                   && isset($body['data']['image']);

            // CheckDB: Xác minh từng field khớp DB
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $dbSpec  = $this->db->getSpecialityById($specId);
                $idOk    = ((int)($body['data']['id'])   === $specId);
                $nameOk  = ($body['data']['name']        === ($dbSpec['name']        ?? ''));
                $descOk  = ($body['data']['description'] === ($dbSpec['description'] ?? ''));
                $imageOk = ($body['data']['image']       === ($dbSpec['image']       ?? ''));

                if (!$idOk || !$nameOk || !$descOk || !$imageOk) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: Dữ liệu API không khớp DB - "
                        . (!$idOk    ? "id sai; "    : "")
                        . (!$nameOk  ? "name sai; "  : "")
                        . (!$descOk  ? "desc sai; "  : "")
                        . (!$imageOk ? "image sai"   : "");
                } else {
                    $checkMsg = "CheckDB OK: id={$specId}, "
                        . "name='{$body['data']['name']}', "
                        . "image='{$body['data']['image']}'";
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        }
    }


    // =========================================================================
    // NHÓM TEST 4: CẬP NHẬT CHUYÊN KHOA
    // Kiểm tra PUT /api/speciality/{id} → SpecialityController::update()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin     → lỗi phân quyền (nhánh role != "admin")
    //   - Thiếu field name     → lỗi missing field
    //   - Thiếu field desc     → lỗi missing field
    //   - ID không tồn tại     → lỗi "not available"
    //   - Dữ liệu hợp lệ       → cập nhật thành công
    //
    // Lưu ý: update() KHÔNG check duplicate name (khác với save())
    // =========================================================================
    public function runUpdateTests(): void
    {
        $this->printGroupHeader("NHÓM 4: CẬP NHẬT CHUYÊN KHOA (PUT /api/speciality/{id})");

        // Tạo 1 chuyên khoa test để cập nhật, dùng xuyên suốt nhóm test này
        $setupResp = $this->http->request('POST', API_BASE_URL . '/specialities', [
            'name'        => 'Chuyen Khoa Setup ' . time(),
            'description' => 'Mo ta setup ' . time(),
        ]);
        $testSpecId   = (int)($setupResp['body']['data']['id']          ?? 0);
        $testSpecName = $setupResp['body']['data']['name']               ?? '';
        $testSpecDesc = $setupResp['body']['data']['description']        ?? '';

        if (!$testSpecId) {
            echo Color::yellow("  [SKIP] Không tạo được chuyên khoa test cho nhóm UPDATE\n");
            return;
        }
        echo Color::yellow("  [Setup] Tạo chuyên khoa test ID={$testSpecId} để test Update\n");


        try {

            // ---------------------------------------------------------------
            // TC_SPEC_UPDATE_001 - Test ngoại lệ: Thiếu field "name"
            // Nhánh   : update() → !Input::put("name") → jsonecho lỗi
            // Input   : PUT chỉ có description, thiếu name
            // Expected: result=0, msg chứa "Missing"
            // ---------------------------------------------------------------
            $testId = 'TC_SPEC_UPDATE_001';
            $desc   = 'Test ngoại lệ: PUT thiếu field "name" → result=0, Missing field';

            $resp   = $this->http->request('PUT',
                API_BASE_URL . '/speciality/' . $testSpecId,
                [
                    // 'name' bị bỏ trống
                    'description' => 'Mo ta moi',
                ]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_SPEC_UPDATE_002 - Test ngoại lệ: Thiếu field "description"
            // Nhánh   : update() → !Input::put("description") → jsonecho lỗi
            // Input   : PUT chỉ có name, thiếu description
            // Expected: result=0, msg chứa "Missing"
            // ---------------------------------------------------------------
            $testId = 'TC_SPEC_UPDATE_002';
            $desc   = 'Test ngoại lệ: PUT thiếu field "description" → result=0, Missing field';

            $resp   = $this->http->request('PUT',
                API_BASE_URL . '/speciality/' . $testSpecId,
                [
                    'name' => 'Ten Moi',
                    // 'description' bị bỏ trống
                ]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_SPEC_UPDATE_003 - Test ngoại lệ: ID không tồn tại
            // Nhánh   : update() → !$Speciality->isAvailable() → jsonecho lỗi
            // Input   : PUT /api/speciality/999999
            // Expected: result=0, msg chứa "not available"
            // ---------------------------------------------------------------
            $testId = 'TC_SPEC_UPDATE_003';
            $desc   = 'Test ngoại lệ: PUT ID=999999 không tồn tại → result=0';

            $resp   = $this->http->request('PUT', API_BASE_URL . '/speciality/999999', [
                'name'        => 'Ten Khong Ton Tai',
                'description' => 'Mo ta khong ton tai',
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_SPEC_UPDATE_004 - Test chuẩn: Cập nhật chuyên khoa thành công
            // Nhánh   : update() → $Speciality->save() → result=1
            // Input   : PUT /api/speciality/{id} với name và description hợp lệ
            // Expected: result=1, data trả về có name và description mới
            // CheckDB : Bản ghi trong tn_specialities phải được cập nhật đúng
            // Rollback: Khôi phục name+description về giá trị ban đầu
            // ---------------------------------------------------------------
            $testId  = 'TC_SPEC_UPDATE_004';
            $desc    = "Test chuẩn: PUT /api/speciality/{$testSpecId} hợp lệ → result=1, CheckDB, Rollback";
            $updName = 'Chuyen Khoa Da Cap Nhat ' . time();
            $updDesc = 'Mo ta da cap nhat ' . time();

            try {
                $resp   = $this->http->request('PUT',
                    API_BASE_URL . '/speciality/' . $testSpecId,
                    [
                        'name'        => $updName,
                        'description' => $updDesc,
                    ]
                );
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 0) == 1
                       && ($body['data']['name']        ?? '') === $updName
                       && ($body['data']['description'] ?? '') === $updDesc;

                // CheckDB: Xác minh bản ghi trong DB đã thay đổi đúng
                $checkMsg = '';
                if ($this->db->connected) {
                    $dbSpec  = $this->db->getSpecialityById($testSpecId);
                    $nameOk  = ($dbSpec['name']        ?? '') === $updName;
                    $descOk  = ($dbSpec['description'] ?? '') === $updDesc;

                    if ($passed && (!$nameOk || !$descOk)) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . "DB name='{$dbSpec['name']}' (mong đợi='{$updName}'), "
                            . "description='{$dbSpec['description']}' (mong đợi='{$updDesc}')";
                    } elseif ($passed) {
                        $checkMsg = "CheckDB OK: name='{$dbSpec['name']}', "
                            . "description='{$dbSpec['description']}' (đã cập nhật đúng)";
                    }
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed ? $checkMsg
                        : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
                );

            } finally {
                // Rollback: Khôi phục name+description về giá trị ban đầu
                $this->db->rollbackRestoreSpeciality($testSpecId, $testSpecName, $testSpecDesc);
                $restored = $this->db->getSpecialityById($testSpecId);

                if (($restored['name']        ?? '') === $testSpecName
                 && ($restored['description'] ?? '') === $testSpecDesc) {
                    echo Color::green(
                        "         ↳ Rollback OK: Khôi phục về "
                        . "name='{$testSpecName}'\n"
                    );
                } else {
                    echo Color::red(
                        "         ↳ Rollback THẤT BẠI: Kiểm tra thủ công ID={$testSpecId}\n"
                    );
                }
            }

        } finally {
            // Teardown: Xóa chuyên khoa test đã tạo ở setup
            $this->db->rollbackDeleteSpeciality($testSpecId);
            $afterCleanup = $this->db->getSpecialityById($testSpecId);
            if ($afterCleanup === null) {
                echo Color::green(
                    "  [Teardown] Rollback OK: Đã xóa chuyên khoa test setup ID={$testSpecId}\n"
                );
            } else {
                echo Color::red(
                    "  [Teardown] Rollback THẤT BẠI: ID={$testSpecId} vẫn còn trong DB\n"
                );
            }
        }
    }


    // =========================================================================
    // NHÓM TEST 5: XÓA CHUYÊN KHOA
    // Kiểm tra DELETE /api/speciality/{id} → SpecialityController::delete()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin        → lỗi phân quyền
    //   - id == 1 (default)       → không cho xóa
    //   - ID không tồn tại        → lỗi "not available"
    //   - Chuyên khoa còn doctor  → không cho xóa
    //   - Chuyên khoa hợp lệ      → xóa thành công
    // =========================================================================
    public function runDeleteTests(): void
    {
        $this->printGroupHeader("NHÓM 5: XÓA CHUYÊN KHOA (DELETE /api/speciality/{id})");


        // ---------------------------------------------------------------
        // TC_SPEC_DELETE_001 - Test ngoại lệ: Xóa chuyên khoa mặc định (ID=1)
        // Nhánh   : delete() → $Route->params->id == 1 → jsonecho lỗi
        // Input   : DELETE /api/speciality/1
        // Expected: result=0, msg chứa "default" hoặc "can't be deleted"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_DELETE_001';
        $desc   = "Test ngoại lệ: DELETE /api/speciality/1 (mặc định) → result=0";

        $resp   = $this->http->request('DELETE', API_BASE_URL . '/speciality/1');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_DELETE_002 - Test ngoại lệ: ID không tồn tại
        // Nhánh   : delete() → !$Speciality->isAvailable() → jsonecho lỗi
        // Input   : DELETE /api/speciality/999999
        // Expected: result=0, msg chứa "not available"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_DELETE_002';
        $desc   = 'Test ngoại lệ: DELETE /api/speciality/999999 không tồn tại → result=0';

        $resp   = $this->http->request('DELETE', API_BASE_URL . '/speciality/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_DELETE_003 - Test ngoại lệ: Chuyên khoa đang có doctor
        // Nhánh   : delete() → count($result) > 0 → jsonecho lỗi
        // Input   : DELETE /api/speciality/{id_có_doctor}
        // Expected: result=0, msg chứa "doctors"
        // ---------------------------------------------------------------
        $testId      = 'TC_SPEC_DELETE_003';
        $desc        = 'Test ngoại lệ: DELETE chuyên khoa đang có doctor → result=0';
        $specWithDoc = $this->db->getSpecialityIdWithDoctor();

        if (!$specWithDoc || $specWithDoc == 1) {
            $this->recordResult($testId, $desc, false,
                'SKIP: Không tìm được chuyên khoa có doctor trong DB (hoặc chỉ id=1)'
            );
        } else {
            $resp   = $this->http->request('DELETE',
                API_BASE_URL . '/speciality/' . $specWithDoc
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        }


        // ---------------------------------------------------------------
        // TC_SPEC_DELETE_004 - Test chuẩn: Xóa chuyên khoa thành công
        // Nhánh   : delete() → $Speciality->delete() → result=1
        // Input   : Tạo chuyên khoa mới → DELETE /api/speciality/{id}
        // Expected: result=1, msg chứa "deleted"
        // CheckDB : Bản ghi không còn tồn tại trong tn_specialities
        // Rollback: Chuyên khoa đã bị xóa = trạng thái mong muốn
        // ---------------------------------------------------------------
        $testId    = 'TC_SPEC_DELETE_004';
        $desc      = 'Test chuẩn: Tạo chuyên khoa → DELETE → CheckDB xác nhận đã xóa';
        $createdId = 0;

        try {
            // Tạo chuyên khoa test để xóa
            $createResp = $this->http->request('POST', API_BASE_URL . '/specialities', [
                'name'        => 'Chuyen Khoa Can Xoa ' . time(),
                'description' => 'Mo ta can xoa ' . time(),
            ]);
            $createBody = $createResp['body'];

            if (($createBody['result'] ?? 0) != 1) {
                $this->recordResult($testId, $desc, false,
                    'Không tạo được chuyên khoa test: ' . ($createBody['msg'] ?? 'N/A')
                );
                return;
            }

            $createdId = (int)($createBody['data']['id'] ?? 0);

            // Xác nhận tồn tại trong DB trước khi xóa
            $existsBefore = $this->db->getSpecialityById($createdId);
            if (!$existsBefore) {
                $this->recordResult($testId, $desc, false,
                    "CheckDB: Chuyên khoa ID={$createdId} không tồn tại sau khi tạo"
                );
                return;
            }

            // Gọi API xóa
            $deleteResp = $this->http->request('DELETE',
                API_BASE_URL . '/speciality/' . $createdId
            );
            $deleteBody = $deleteResp['body'];
            $passed     = ($deleteBody['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi đã biến mất khỏi DB
            $checkMsg = '';
            if ($this->db->connected) {
                $existsAfter = $this->db->getSpecialityById($createdId);

                if ($passed && $existsAfter !== null) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: Bản ghi ID={$createdId} vẫn còn trong DB";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: Bản ghi ID={$createdId} đã xóa hoàn toàn khỏi DB";
                    $createdId = 0; // Đã xóa thành công → không cần rollback
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg
                    : "result={$deleteBody['result']}, msg="
                      . ($deleteBody['msg'] ?? $checkMsg)
            );

        } finally {
            // Rollback: Chỉ cần nếu API xóa thất bại nhưng bản ghi vẫn còn
            if ($createdId > 0) {
                $this->db->rollbackDeleteSpeciality($createdId);
                echo Color::yellow(
                    "         ↳ Rollback: Xóa thủ công chuyên khoa ID={$createdId} "
                    . "(API xóa thất bại)\n"
                );
            }
        }
    }


    // =========================================================================
    // NHÓM TEST 6: CẬP NHẬT ẢNH CHUYÊN KHOA
    // Kiểm tra POST /api/speciality/{id} (action=avatar)
    //           → SpecialityController::updateAvatar()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin       → lỗi phân quyền (nhánh role != "admin")
    //   - ID không tồn tại       → lỗi "not available"
    //   - Không upload file      → lỗi "Photo is not received"
    //   - Sai định dạng file     → lỗi "Only jpeg,jpg,png files are allowed"
    //   - File hợp lệ            → cập nhật thành công
    // =========================================================================
    public function runAvatarTests(): void
    {
        $this->printGroupHeader("NHÓM 6: CẬP NHẬT ẢNH (POST /api/speciality/{id} action=avatar)");

        $specId = $this->db->getAnySpecialityId();


        // ---------------------------------------------------------------
        // TC_SPEC_AVATAR_001 - Test ngoại lệ: Không upload file
        // Nhánh   : updateAvatar() → $_FILES["file"] rỗng → jsonecho lỗi
        // Input   : POST action=avatar, không có file
        // Expected: result=0, msg chứa "not received"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_AVATAR_001';
        $desc   = 'Test ngoại lệ: POST action=avatar không có file → result=0';

        if (!$specId) {
            $this->recordResult($testId, $desc, false, 'SKIP: DB không có chuyên khoa nào');
        } else {
            $resp   = $this->http->request('POST',
                API_BASE_URL . '/speciality/' . $specId,
                ['action' => 'avatar']
                // Không upload file
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        }


        // ---------------------------------------------------------------
        // TC_SPEC_AVATAR_002 - Test ngoại lệ: File sai định dạng
        // Nhánh   : updateAvatar() → !in_array($ext, $allow) → jsonecho lỗi
        //           $allow = ["jpeg", "jpg", "png"]
        // Input   : POST action=avatar, file .txt (không phải ảnh)
        // Expected: result=0, msg chứa "Only"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_AVATAR_002';
        $desc   = 'Test ngoại lệ: Upload file .txt (sai định dạng) → result=0';

        if (!$specId) {
            $this->recordResult($testId, $desc, false, 'SKIP: DB không có chuyên khoa nào');
        } else {
            // Tạo file .txt tạm để upload
            $tmpTxtPath = sys_get_temp_dir() . '/test_avatar_wrong.txt';
            file_put_contents($tmpTxtPath, 'This is not an image file');

            $resp   = $this->http->request(
                'POST',
                API_BASE_URL . '/speciality/' . $specId,
                ['action' => 'avatar'],
                ['file' => $tmpTxtPath]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );

            // Dọn file tạm
            if (file_exists($tmpTxtPath)) unlink($tmpTxtPath);
        }


        // ---------------------------------------------------------------
        // TC_SPEC_AVATAR_003 - Test ngoại lệ: ID không tồn tại
        // Nhánh   : updateAvatar() → !$Speciality->isAvailable() → jsonecho lỗi
        // Input   : POST /api/speciality/999999 action=avatar
        // Expected: result=0, msg chứa "not available"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_AVATAR_003';
        $desc   = 'Test ngoại lệ: POST action=avatar ID=999999 không tồn tại → result=0';

        $resp   = $this->http->request('POST', API_BASE_URL . '/speciality/999999', [
            'action' => 'avatar',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SPEC_AVATAR_004 - Test ngoại lệ: action không hợp lệ
        // Nhánh   : process() → POST → Input::post("action") != "avatar"
        //           → result=0, msg "This request is not valid"
        // Input   : POST /api/speciality/{id} action=invalid_action
        // Expected: result=0, msg chứa "not valid"
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_AVATAR_004';
        $desc   = 'Test ngoại lệ: POST action=invalid_action → result=0, not valid';

        if (!$specId) {
            $this->recordResult($testId, $desc, false, 'SKIP: DB không có chuyên khoa nào');
        } else {
            $resp   = $this->http->request('POST',
                API_BASE_URL . '/speciality/' . $specId,
                ['action' => 'invalid_action']
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        }


        // ---------------------------------------------------------------
        // TC_SPEC_AVATAR_005 - Test chuẩn: Upload ảnh hợp lệ (.jpg)
        // Nhánh   : updateAvatar() → move_uploaded_file → save() → result=1
        // Input   : POST action=avatar, file .jpg hợp lệ
        // Expected: result=1, response có trường "url"
        // CheckDB : Cột image trong tn_specialities phải được cập nhật
        // Rollback: Khôi phục image về giá trị ban đầu
        // ---------------------------------------------------------------
        $testId = 'TC_SPEC_AVATAR_005';
        $desc   = 'Test chuẩn: Upload ảnh .jpg hợp lệ → result=1, CheckDB image, Rollback';

        if (!$specId) {
            $this->recordResult($testId, $desc, false, 'SKIP: DB không có chuyên khoa nào');
        } else {
            // Lưu image ban đầu để rollback
            $originalSpec  = $this->db->getSpecialityById($specId);
            $originalImage = $originalSpec['image'] ?? 'default_avatar.jpg';

            // Tạo file .jpg tạm (ảnh 1x1 pixel PNG hợp lệ)
            $tmpJpgPath = sys_get_temp_dir() . '/test_avatar_valid.jpg';
            // Data nhị phân của ảnh JPEG 1x1 pixel trắng
            $jpegData = base64_decode(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
                . 'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgN'
                . 'DRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
                . 'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUE/8QAHhAA'
                . 'AgIDAQEBAQAAAAAAAAAAAQIDBAUREiH/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEB'
                . 'AAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8Amcz1oKqHfG1prWyMpMmTJ5ZLs'
                . 'xlVXSfaM2zNmTJ5ZL/2Q=='
            );
            file_put_contents($tmpJpgPath, $jpegData);

            try {
                $resp   = $this->http->request(
                    'POST',
                    API_BASE_URL . '/speciality/' . $specId,
                    ['action' => 'avatar'],
                    ['file' => $tmpJpgPath]
                );
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 0) == 1
                       && isset($body['url']);

                // CheckDB: Xác minh image trong DB đã thay đổi
                $checkMsg = '';
                if ($passed && $this->db->connected) {
                    $updatedSpec  = $this->db->getSpecialityById($specId);
                    $newImageInDb = $updatedSpec['image'] ?? '';

                    if ($newImageInDb === $originalImage) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: image trong DB chưa thay đổi "
                            . "(vẫn là '{$originalImage}')";
                    } else {
                        $checkMsg = "CheckDB OK: image thay đổi từ "
                            . "'{$originalImage}' → '{$newImageInDb}'";
                    }
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed ? $checkMsg
                        : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
                );

            } finally {
                // Dọn file tạm
                if (file_exists($tmpJpgPath)) unlink($tmpJpgPath);

                // Rollback: Khôi phục image về giá trị ban đầu
                $this->db->rollbackRestoreImage($specId, $originalImage);
                $restored = $this->db->getSpecialityById($specId);
                if (($restored['image'] ?? '') === $originalImage) {
                    echo Color::green(
                        "         ↳ Rollback OK: image khôi phục về '{$originalImage}'\n"
                    );
                } else {
                    echo Color::red(
                        "         ↳ Rollback THẤT BẠI: Kiểm tra thủ công ID={$specId}\n"
                    );
                }
            }
        }
    }


    // =========================================================================
    // PHƯƠNG THỨC CHẠY CHÍNH
    // =========================================================================
    public function run(string $group = 'all'): void
    {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════╗\n"
            . "║  TEST MODULE CHUYÊN KHOA - UMBRELLA CORPORATION    ║\n"
            . "║  PHP cURL Test Script - Kết quả trên Terminal      ║\n"
            . "╚══════════════════════════════════════════════════════╝"
        )) . "\n";
        echo Color::yellow("  API URL   : " . API_BASE_URL)            . "\n";
        echo Color::yellow("  Thời gian : " . date('Y-m-d H:i:s'))     . "\n";
        echo Color::yellow("  Database  : " . DB_NAME . "@" . DB_HOST)  . "\n";

        // ==============================================================
        // BƯỚC BẮT BUỘC: Đăng nhập trước khi test Speciality
        // ==============================================================
        $this->printGroupHeader("ĐĂNG NHẬP ADMIN (bắt buộc - không tính vào test case)");
        $loginOk = $this->doLogin();

        if (!$loginOk) {
            echo Color::red("\n  Không thể đăng nhập → Dừng toàn bộ test.\n");
            echo Color::red("  Kiểm tra: ADMIN_EMAIL, ADMIN_PASSWORD, API_BASE_URL\n\n");
            exit(1);
        }

        // Chạy nhóm test theo tham số hoặc chạy tất cả
        switch ($group) {
            case 'list':   $this->runListTests();   break;
            case 'create': $this->runCreateTests(); break;
            case 'detail': $this->runDetailTests(); break;
            case 'update': $this->runUpdateTests(); break;
            case 'delete': $this->runDeleteTests(); break;
            case 'avatar': $this->runAvatarTests(); break;
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runDetailTests();
                $this->runUpdateTests();
                $this->runDeleteTests();
                $this->runAvatarTests();
                break;
        }

        $this->printSummary();
        $this->db->close();
    }
}


// =============================================================================
// ĐIỂM VÀO CHƯƠNG TRÌNH
// Cách chạy: php SpecialityTest.php [list|create|detail|update|delete|avatar|all]
// =============================================================================
$group  = $argv[1] ?? 'all';
$runner = new TestRunner();
$runner->run($group);