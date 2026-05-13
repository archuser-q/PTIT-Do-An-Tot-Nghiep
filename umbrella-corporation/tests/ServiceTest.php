<?php
/**
 * =============================================================================
 * FILE:    ServiceTest.php
 * PROJECT: Umbrella Corporation - Hệ thống quản lý phòng khám
 * MODULE:  Dịch vụ (Service)
 * AUTHOR:  Test Engineer
 * DATE:    2026-05-11
 *
 * MÔ TẢ:
 *   Test script PHP cho module Dịch vụ, gọi trực tiếp API bằng cURL.
 *   Chạy hoàn toàn trên terminal, KHÔNG cần trình duyệt.
 *
 * QUY TRÌNH TEST:
 *   Bước 1: Với mỗi method, xây dựng test case với input/expected output rõ ràng
 *           - Test chuẩn   : input hợp lệ, hệ thống xử lý đúng
 *           - Test ngoại lệ: input không hợp lệ, hệ thống trả lỗi đúng
 *   Bước 2: Gọi API, kiểm tra kết quả, CheckDB xác minh thay đổi
 *   Bước 3: Rollback trong finally block để DB luôn về trạng thái ban đầu
 *
 * CẤU TRÚC API (từ routes.inc.php):
 *   GET    API/services              → ServicesController::getAll()     - Danh sách dịch vụ
 *   POST   API/services              → ServicesController::save()       - Tạo mới dịch vụ
 *   GET    API/services/{id}         → ServiceController::getById()     - Chi tiết dịch vụ
 *   PUT    API/services/{id}         → ServiceController::update()      - Cập nhật dịch vụ
 *   DELETE API/services/{id}         → ServiceController::delete()      - Xóa dịch vụ
 *   GET    API/doctors-and-services/{id} → DoctorsAndServicesController::getAll()  - DS bác sĩ theo dịch vụ
 *   POST   API/doctors-and-services/{id} → DoctorsAndServicesController::create() - Thêm bác sĩ vào dịch vụ
 *   DELETE API/doctors-and-services/{id} → DoctorsAndServicesController::delete() - Xóa bác sĩ khỏi dịch vụ
 *
 * PHÂN QUYỀN:
 *   ServicesController::getAll()     : Tất cả (không cần phân quyền - đã comment out)
 *   ServicesController::save()       : Chỉ admin
 *   ServiceController::getById()    : Tất cả (đã comment out check)
 *   ServiceController::update()     : Chỉ admin
 *   ServiceController::delete()     : Chỉ admin
 *   DoctorsAndServicesController::* : Chỉ admin
 *
 * DATABASE: nextpost
 *   tn_services         : id, name, image, description
 *   tn_doctor_and_service: id, service_id, doctor_id
 *
 * CÁCH CHẠY:
 *   php ServiceTest.php              → Chạy tất cả
 *   php ServiceTest.php list         → Danh sách dịch vụ
 *   php ServiceTest.php create       → Tạo mới dịch vụ
 *   php ServiceTest.php detail       → Chi tiết dịch vụ
 *   php ServiceTest.php update       → Cập nhật dịch vụ
 *   php ServiceTest.php delete       → Xóa dịch vụ
 *   php ServiceTest.php doctor       → Quản lý bác sĩ-dịch vụ
 * =============================================================================
 */

// =============================================================================
// CẤU HÌNH
// =============================================================================
define('API_BASE_URL',    'http://localhost:8080/PTIT-Do-An-Tot-Nghiep/api');
define('ADMIN_EMAIL',     'phongkaster@gmail.com');
define('ADMIN_PASSWORD',  '123456');
define('MEMBER_EMAIL',    'doctor.member@test.com');
define('MEMBER_PASSWORD', '123456');
define('DB_HOST',         '127.0.0.1');
define('DB_PORT',         3306);
define('DB_NAME',         'nextpost');
define('DB_USER',         'root');
define('DB_PASSWORD',     'Thanh@123');
define('TABLE_SERVICES',         'tn_services');
define('TABLE_DOCTOR_AND_SERVICE','tn_doctor_and_service');
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


/**
 * Lớp hỗ trợ kết nối và thao tác database.
 *
 * Dùng cho:
 *   - CheckDB: Xác minh dữ liệu thay đổi đúng sau khi gọi API
 *   - Rollback: Trong finally block, khôi phục DB về trạng thái ban đầu
 *
 * Bảng chính:
 *   tn_services          : id, name, image, description
 *   tn_doctor_and_service: id, service_id, doctor_id
 */
class DatabaseHelper
{
    private ?PDO $pdo      = null;
    public bool $connected = false;

    public function __construct()
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                           DB_HOST, DB_PORT, DB_NAME);
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

    /** Thực thi SELECT và trả về danh sách bản ghi */
    public function query(string $sql, array $params = []): array
    {
        if (!$this->connected) return [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Thực thi INSERT/UPDATE/DELETE */
    public function execute(string $sql, array $params = []): void
    {
        if (!$this->connected) return;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** Lấy bản ghi service theo ID. Dùng cho CheckDB */
    public function getServiceById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_SERVICES . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lấy bản ghi service mới nhất theo tên. Dùng cho CheckDB */
    public function getServiceByName(string $name): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_SERVICES
            . " WHERE name = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ROLLBACK: Xóa service vừa tạo trong test.
     * Gọi trong finally block để đảm bảo DB về trạng thái ban đầu.
     */
    public function rollbackDeleteService(int $id): void
    {
        if (!$this->connected) return;
        $this->execute(
            "DELETE FROM " . TABLE_SERVICES . " WHERE id = ?",
            [$id]
        );
    }

    /**
     * ROLLBACK: Khôi phục name và description của service về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật.
     */
    public function rollbackRestoreService(int $id, string $originalName, string $originalDesc): void
    {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_SERVICES . " SET name = ?, description = ? WHERE id = ?",
            [$originalName, $originalDesc, $id]
        );
    }

    /** Lấy ID bất kỳ của service (không phải ID=1 vì ID=1 không xóa được) */
    public function getServiceIdNotDefault(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT id FROM " . TABLE_SERVICES . " WHERE id != 1 LIMIT 1"
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /** Lấy ID bất kỳ của service (bao gồm cả ID=1) */
    public function getAnyServiceId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM " . TABLE_SERVICES . " LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /** Lấy ID bác sĩ active để test thêm vào dịch vụ */
    public function getActiveDoctorId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM tn_doctors WHERE active = 1 LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Lấy bản ghi doctor_and_service theo ID.
     * Dùng cho CheckDB sau khi thêm/xóa bác sĩ-dịch vụ.
     */
    public function getDoctorAndServiceById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_DOCTOR_AND_SERVICE . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ROLLBACK: Xóa bản ghi doctor_and_service vừa tạo.
     * Gọi trong finally block sau test thêm bác sĩ vào dịch vụ.
     */
    public function rollbackDeleteDoctorAndService(int $id): void
    {
        if (!$this->connected) return;
        $this->execute(
            "DELETE FROM " . TABLE_DOCTOR_AND_SERVICE . " WHERE id = ?",
            [$id]
        );
    }

    /** Kiểm tra service có booking liên quan không */
    public function serviceHasBooking(int $serviceId): bool
    {
        if (!$this->connected) return false;
        $rows = $this->query(
            "SELECT id FROM tn_booking WHERE service_id = ? LIMIT 1",
            [$serviceId]
        );
        return count($rows) > 0;
    }

    /** Kiểm tra service có doctor liên quan không */
    public function serviceHasDoctor(int $serviceId): bool
    {
        if (!$this->connected) return false;
        $rows = $this->query(
            "SELECT id FROM " . TABLE_DOCTOR_AND_SERVICE . " WHERE service_id = ? LIMIT 1",
            [$serviceId]
        );
        return count($rows) > 0;
    }

    public function close(): void { $this->pdo = null; }
}


/**
 * Lớp HTTP Client dùng cURL để gọi API.
 * API dùng header: Authorization: JWT {token}, type: Doctor
 */
class HttpClient
{
    private ?string $accessToken = null;

    public function setToken(string $token): void { $this->accessToken = $token; }
    public function clearToken(): void            { $this->accessToken = null; }

    /**
     * Thực hiện HTTP request bằng cURL.
     *
     * @param  string $method  GET/POST/PUT/PATCH/DELETE
     * @param  string $url     URL đầy đủ của API endpoint
     * @param  array  $data    Dữ liệu gửi kèm
     * @return array           ['status' => int, 'body' => array]
     */
    public function request(string $method, string $url, array $data = []): array
    {
        $ch      = curl_init();
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

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['status' => 0, 'body' => ['result' => 0, 'msg' => 'cURL Error: ' . $curlError]];
        }

        $parsed = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = ['result' => 0, 'msg' => 'Invalid JSON: ' . $responseBody];
        }

        return ['status' => $httpStatus, 'body' => $parsed];
    }
}


/**
 * Lớp TestRunner chạy và quản lý tất cả test case module Service.
 */
class TestRunner
{
    private int   $totalTests  = 0;
    private int   $passedTests = 0;
    private int   $failedTests = 0;
    private array $failedList  = [];

    private HttpClient     $http;       // Admin HTTP client
    private HttpClient     $memberHttp; // Member HTTP client (test phân quyền)
    private DatabaseHelper $db;

    private ?string $accessToken = null;
    private ?string $memberToken = null;

    public function __construct()
    {
        $this->http       = new HttpClient();
        $this->memberHttp = new HttpClient();
        $this->db         = new DatabaseHelper();
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function printGroupHeader(string $groupName): void
    {
        echo "\n" . Color::bold(Color::blue(
            "══════════════════════════════════════════════\n"
            . "  {$groupName}\n"
            . "══════════════════════════════════════════════"
        )) . "\n";
    }

    /**
     * Ghi nhận kết quả một test case.
     *
     * @param string $testId      ID test case (TC_SVC_LIST_001)
     * @param string $description Mô tả
     * @param bool   $passed      true = PASS, false = FAIL
     * @param string $message     Chi tiết (nguyên nhân fail, CheckDB, Rollback)
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
            if ($message) echo Color::green("         ↳ {$message}") . "\n";
        } else {
            $this->failedTests++;
            echo sprintf("  %s %s %s\n",
                Color::red('[FAIL]'), Color::cyan($testId), $description);
            if ($message) echo Color::red("         ↳ {$message}") . "\n";
            $this->failedList[] = "{$testId}: {$description}";
            if ($message) $this->failedList[] = "       → {$message}";
        }
    }

    private function printSummary(): void
    {
        echo "\n" . Color::bold("══════════════════════════════════════════════") . "\n";
        echo Color::bold("  KẾT QUẢ TỔNG QUAN") . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n";
        echo sprintf("  Tổng số test : %d\n", $this->totalTests);
        echo sprintf("  %s : %d\n", Color::green('PASS'), $this->passedTests);
        echo sprintf("  %s : %d\n", Color::red('FAIL'),  $this->failedTests);

        if (!empty($this->failedList)) {
            echo "\n" . Color::red(Color::bold("  DANH SÁCH TEST THẤT BẠI:")) . "\n";
            foreach ($this->failedList as $line) {
                echo Color::red("  {$line}") . "\n";
            }
        }

        $rate = $this->totalTests > 0
            ? round($this->passedTests / $this->totalTests * 100, 1) : 0;
        echo "\n" . Color::bold(sprintf("  Tỷ lệ thành công: %s%%", $rate)) . "\n";
        echo Color::bold("══════════════════════════════════════════════") . "\n\n";
    }

    /** Đăng nhập lấy token - không tính vào test case */
    private function doLogin(): void
    {
        $resp = $this->http->request('POST', API_BASE_URL . '/login', [
            'email'    => ADMIN_EMAIL,
            'password' => ADMIN_PASSWORD,
            'type'     => 'doctor',
        ]);
        if (($resp['body']['result'] ?? 0) == 1) {
            $this->accessToken = $resp['body']['accessToken'];
            $this->http->setToken($this->accessToken);
            echo Color::green("  ✓ Admin đăng nhập thành công\n");
        } else {
            echo Color::red("  ✗ Admin đăng nhập thất bại\n");
        }

        $resp = $this->memberHttp->request('POST', API_BASE_URL . '/login', [
            'email'    => MEMBER_EMAIL,
            'password' => MEMBER_PASSWORD,
            'type'     => 'doctor',
        ]);
        if (($resp['body']['result'] ?? 0) == 1) {
            $this->memberToken = $resp['body']['accessToken'];
            $this->memberHttp->setToken($this->memberToken);
            echo Color::green("  ✓ Member doctor đăng nhập thành công\n");
        } else {
            echo Color::yellow("  ⚠ Member doctor đăng nhập thất bại\n");
        }
    }


    // =========================================================================
    // NHÓM TEST 1: DANH SÁCH DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra GET /services - ServicesController::getAll()
     *
     * Phân quyền: Tất cả (check đã bị comment out trong source code)
     * Bộ lọc    : search, order[column], order[dir], length, start
     *
     * Test chuẩn   : Lấy danh sách với các bộ lọc hợp lệ
     * Test ngoại lệ: Không có token
     */
    public function runListTests(): void
    {
        $this->printGroupHeader("NHÓM 1: DANH SÁCH DỊCH VỤ (GET /services)");


        // ---------------------------------------------------------------
        // TC_SVC_LIST_001 - Test chuẩn 1
        // Input  : GET /services (không bộ lọc), token hợp lệ
        // Expected: result=1, data là mảng, có trường quantity
        //           Mỗi phần tử có: id, name, image, description
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_LIST_001';
        $desc   = 'Test chuẩn: GET không bộ lọc → result=1, data là mảng, có quantity';
        $resp   = $this->http->request('GET', API_BASE_URL . '/services');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);
        // Kiểm tra thêm cấu trúc phần tử nếu có data
        if ($passed && !empty($body['data'])) {
            $first  = $body['data'][0];
            $passed = array_key_exists('id',          $first)
                   && array_key_exists('name',        $first)
                   && array_key_exists('image',       $first)
                   && array_key_exists('description', $first);
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, quantity={$body['quantity']}, data count=" . count($body['data'])
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );




        // ---------------------------------------------------------------
        // TC_SVC_LIST_002 - Test chuẩn 3
        // Input  : GET /services?length=3&start=0
        // Expected: result=1, trả về tối đa 3 bản ghi
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_LIST_002';
        $desc   = 'Test chuẩn: Phân trang length=3 → trả về tối đa 3 bản ghi';
        $resp   = $this->http->request('GET', API_BASE_URL . '/services', ['length' => 3, 'start' => 0]);
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 3;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về {$count} bản ghi (≤ 3)"
                : "Trả về {$count} bản ghi (mong đợi ≤ 3)"
        );


        // ---------------------------------------------------------------
        // TC_SVC_LIST_003 - Test chuẩn 4
        // Input  : GET /services?order[column]=name&order[dir]=asc
        // Expected: result=1, danh sách sắp xếp theo name tăng dần
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_LIST_003';
        $desc   = 'Test chuẩn: Sắp xếp order[column]=name&order[dir]=asc → đúng thứ tự';
        $resp   = $this->http->request('GET', API_BASE_URL . '/services', [
            'order' => ['column' => 'name', 'dir' => 'asc']
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && count($body['data'] ?? []) > 1) {
            $names = array_column($body['data'], 'name');
            $sorted = $names;
            sort($sorted, SORT_STRING | SORT_FLAG_CASE);
            $passed = ($names === $sorted);
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Danh sách đã được sắp xếp đúng theo name ASC"
                : "Danh sách chưa được sắp xếp đúng theo name ASC"
        );


        // ---------------------------------------------------------------
        // TC_SVC_LIST_004 - Test ngoại lệ 1
        // Input  : GET /services (không có token)
        // Expected: HTTP 302 redirect về login
        //           ServicesController::process() dòng 14-17: if(!$AuthUser) redirect login
        //           Dù getAll() comment out check phân quyền, nhưng process() vẫn check token
        // ---------------------------------------------------------------
        $testId   = 'TC_SVC_LIST_004';
        $desc     = 'Test ngoại lệ: Không có token → bị redirect về login (HTTP 302)';
        $tempHttp = new HttpClient(); // Không có token
        $resp     = $tempHttp->request('GET', API_BASE_URL . '/services');
        $body     = $resp['body'];
        // process() dòng 14-17: if(!$AuthUser) redirect → HTTP 302
        $passed   = $resp['status'] == 302 || ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Redirect về login đúng"
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );
    }


    // =========================================================================
    // NHÓM TEST 2: TẠO MỚI DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra POST /services - ServicesController::save()
     *
     * Phân quyền: Chỉ admin
     * Required  : name
     * Ràng buộc : Không trùng tên với service đã có
     *
     * Test chuẩn   : Tạo service với name hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: Thiếu name, trùng tên, MEMBER tạo
     */
    public function runCreateTests(): void
    {
        $this->printGroupHeader("NHÓM 2: TẠO MỚI DỊCH VỤ (POST /services)");


        // ---------------------------------------------------------------
        // TC_SVC_CREATE_001 - Test chuẩn 1
        // Input  : name='Dich Vu Test Moi', description='Mo ta dich vu test'
        // Expected: result=1, msg='Service is created successfully !'
        // CheckDB : Bản ghi tồn tại trong tn_services với name và description đúng
        //           image mặc định = 'default_avatar.jpg'
        // Rollback: Xóa bản ghi vừa tạo
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_CREATE_001';
        $desc      = 'Test chuẩn: Tạo dịch vụ mới hợp lệ → result=1, CheckDB, Rollback';
        $inputName = 'Dich Vu Test Moi';
        $inputDesc = 'Mo ta dich vu test TC_SVC_CREATE_001';
        $createdId = 0;

        try {
            $resp   = $this->http->request('POST', API_BASE_URL . '/services', [
                'name'        => $inputName,
                'description' => $inputDesc,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi trong tn_services
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $record = $this->db->getServiceByName($inputName);
                if (!$record) {
                    $passed   = false;
                    $checkMsg = 'CheckDB THẤT BẠI: Không tìm thấy bản ghi trong tn_services';
                } else {
                    $createdId  = (int)$record['id'];
                    $nameOk     = ($record['name']        === $inputName);
                    $descOk     = ($record['description'] === $inputDesc);
                    $imageOk    = ($record['image']       === 'default_avatar.jpg');

                    if (!$nameOk || !$descOk || !$imageOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . (!$nameOk  ? "name sai; "  : "")
                            . (!$descOk  ? "description sai; " : "")
                            . (!$imageOk ? "image mặc định sai (phải='default_avatar.jpg')" : "");
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId}, "
                            . "name='{$record['name']}', "
                            . "image='{$record['image']}'";
                    }
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn xóa bản ghi test
            if ($createdId > 0) {
                $this->db->rollbackDeleteService($createdId);
                $after = $this->db->getServiceById($createdId);
                echo Color::green("         ↳ Rollback " . ($after === null ? "OK" : "THẤT BẠI")
                    . ": Bản ghi ID={$createdId}\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_SVC_CREATE_002 - Test chuẩn 2
        // Input  : name='Dich Vu Chi Co Ten' (không có description)
        // Expected: result=1 (description không phải required field)
        // CheckDB : description = null hoặc rỗng
        // Rollback: Xóa bản ghi vừa tạo
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_CREATE_002';
        $desc      = 'Test chuẩn: Tạo dịch vụ chỉ có name (không có description) → result=1';
        $inputName = 'Dich Vu Chi Co Ten';
        $createdId = 0;

        try {
            $resp   = $this->http->request('POST', API_BASE_URL . '/services', [
                'name' => $inputName,
                // Không có description
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $record = $this->db->getServiceByName($inputName);
                if ($record) {
                    $createdId = (int)$record['id'];
                    $checkMsg  = "CheckDB OK: ID={$createdId}, name='{$record['name']}'";
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
            );
        } finally {
            if ($createdId > 0) {
                $this->db->rollbackDeleteService($createdId);
                echo Color::green("         ↳ Rollback OK: Bản ghi ID={$createdId} đã xóa\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_SVC_CREATE_003 - Test ngoại lệ 1
        // Input  : Thiếu name (required field)
        // Expected: result=0, msg='Missing field: name'
        //           save() dòng 168-175: required_fields = ["name"]
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_CREATE_003';
        $desc   = 'Test ngoại lệ: Thiếu name → result=0 (required field)';
        $resp   = $this->http->request('POST', API_BASE_URL . '/services', [
            'description' => 'Test thieu name',
            // name bị bỏ qua
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_CREATE_004 - Test ngoại lệ 2
        // Input  : name trùng với dịch vụ đã có trong DB
        // Expected: result=0, msg='This Service exists ! Try another name'
        //           save() dòng 179-185: kiểm tra duplicate name
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_CREATE_004';
        $desc      = 'Test ngoại lệ: Tên dịch vụ đã tồn tại → result=0 (trùng tên)';
        // Lấy tên dịch vụ đầu tiên trong DB để test trùng
        $existingService = $this->db->getServiceById(1);
        $existingName    = $existingService['name'] ?? 'Kham suc khoe tong quat';

        $resp   = $this->http->request('POST', API_BASE_URL . '/services', [
            'name'        => $existingName, // Trùng tên đã có
            'description' => 'Test trung ten dich vu',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (trùng tên), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_CREATE_005 - Test ngoại lệ 3
        // Input  : MEMBER token, POST /services
        // Expected: result=0, msg='You are not admin & you can't do this action !'
        //           save() dòng 158-163: chỉ admin mới tạo được
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_CREATE_005';
        $desc   = 'Test ngoại lệ: MEMBER tạo dịch vụ → result=0 (không có quyền)';
        if ($this->memberToken) {
            $resp   = $this->memberHttp->request('POST', API_BASE_URL . '/services', [
                'name'        => 'Test Member Create Service',
                'description' => 'MEMBER thu tao dich vu',
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER tạo được (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có member token');
        }
    }


    // =========================================================================
    // NHÓM TEST 3: CHI TIẾT DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra GET /services/{id} - ServiceController::getById()
     *
     * Phân quyền: Tất cả (check đã bị comment out trong source code)
     * Response  : id, name, image, description
     *
     * Test chuẩn   : Lấy chi tiết service tồn tại
     * Test ngoại lệ: ID không tồn tại
     */
    public function runGetByIdTests(): void
    {
        $this->printGroupHeader("NHÓM 3: CHI TIẾT DỊCH VỤ (GET /services/{id})");

        $serviceId = $this->db->getAnyServiceId();


        // ---------------------------------------------------------------
        // TC_SVC_DETAIL_001 - Test chuẩn 1
        // Input  : GET /services/{id} với id tồn tại, token admin
        // Expected: result=1, data có đủ: id, name, image, description
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DETAIL_001';
        if (!$serviceId) {
            $this->recordResult($testId, 'Test chuẩn: GET chi tiết hợp lệ', false,
                'SKIP: Không có service trong DB'
            );
        } else {
            $desc = "Test chuẩn: GET /services/{$serviceId} → result=1, đủ thông tin";
            $resp = $this->http->request('GET', API_BASE_URL . '/services/' . $serviceId);
            $body = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && array_key_exists('id',          $body['data'] ?? [])
                   && array_key_exists('name',        $body['data'] ?? [])
                   && array_key_exists('image',       $body['data'] ?? [])
                   && array_key_exists('description', $body['data'] ?? []);
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=1, name='{$body['data']['name']}'"
                    : "result={$body['result']}, thiếu trường bắt buộc"
            );
        }


        // ---------------------------------------------------------------
        // TC_SVC_DETAIL_002 - Test ngoại lệ 1
        // Input  : GET /services/999999 (ID không tồn tại)
        // Expected: result=0, msg='Service is not available'
        //           getById() dòng 72-76: check isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DETAIL_002';
        $desc   = 'Test ngoại lệ: GET ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/services/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_DETAIL_003 - Test ngoại lệ 2
        // Input  : GET /services/{id} không có token
        // Expected: HTTP 302 redirect về login
        //           ServiceController::process() dòng 12-15: if(!$AuthUser) redirect login
        //           Dù getById() comment out check phân quyền bên trong, nhưng process() vẫn check token
        // ---------------------------------------------------------------
        $testId   = 'TC_SVC_DETAIL_003';
        $desc     = 'Test ngoại lệ: GET chi tiết không có token → bị redirect về login (HTTP 302)';
        $tempHttp = new HttpClient();
        if ($serviceId) {
            $resp   = $tempHttp->request('GET', API_BASE_URL . '/services/' . $serviceId);
            $body   = $resp['body'];
            // process() check token → HTTP 302
            $passed = $resp['status'] == 302 || ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "HTTP {$resp['status']}: Redirect về login đúng"
                    : "result={$body['result']}"
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có service trong DB');
        }
    }


    // =========================================================================
    // NHÓM TEST 4: CẬP NHẬT DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra PUT /services/{id} - ServiceController::update()
     *
     * Phân quyền: Chỉ admin
     * Required  : name, description (cả 2 đều bắt buộc)
     *
     * Test chuẩn   : Cập nhật service hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: ID không tồn tại, thiếu name/description, MEMBER cập nhật
     */
    public function runUpdateTests(): void
    {
        $this->printGroupHeader("NHÓM 4: CẬP NHẬT DỊCH VỤ (PUT /services/{id})");

        $serviceId = $this->db->getAnyServiceId();
        if (!$serviceId) {
            echo Color::yellow("  [SKIP] Không có service trong DB\n");
            return;
        }

        $originalRecord = $this->db->getServiceById($serviceId);
        $originalName   = $originalRecord['name']        ?? 'Ten Goc';
        $originalDesc   = $originalRecord['description'] ?? 'Mo ta goc';


        // ---------------------------------------------------------------
        // TC_SVC_UPDATE_001 - Test chuẩn 1
        // Input  : PUT /services/{id}, name='Ten Dich Vu Da Cap Nhat',
        //          description='Mo ta da cap nhat'
        // Expected: result=1, msg='Service has been updated successfully'
        // CheckDB : name và description trong DB đã thay đổi đúng
        // Rollback: Khôi phục name và description về giá trị gốc
        // ---------------------------------------------------------------
        $testId  = 'TC_SVC_UPDATE_001';
        $desc    = "Test chuẩn: Cập nhật name và description ID={$serviceId} → CheckDB → Rollback";
        $newName = 'Ten Dich Vu Da Cap Nhat';
        $newDesc = 'Mo ta da duoc cap nhat TC_SVC_UPDATE_001';

        try {
            $resp   = $this->http->request('PUT',
                API_BASE_URL . '/services/' . $serviceId,
                ['name' => $newName, 'description' => $newDesc]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh name và description đã thay đổi trong tn_services
            $checkMsg = '';
            if ($this->db->connected) {
                $updatedRecord = $this->db->getServiceById($serviceId);
                $nameInDb      = $updatedRecord['name']        ?? '';
                $descInDb      = $updatedRecord['description'] ?? '';

                if ($passed && ($nameInDb !== $newName || $descInDb !== $newDesc)) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: "
                        . ($nameInDb !== $newName ? "name='{$nameInDb}' (mong đợi='{$newName}'); " : "")
                        . ($descInDb !== $newDesc ? "description sai"                              : "");
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: name='{$nameInDb}' (đã cập nhật đúng)";
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn khôi phục name và description về giá trị gốc
            $this->db->rollbackRestoreService($serviceId, $originalName, $originalDesc);
            $restored = $this->db->getServiceById($serviceId);
            if (($restored['name'] ?? '') === $originalName) {
                echo Color::green("         ↳ Rollback OK: name về '{$originalName}'\n");
            } else {
                echo Color::red("         ↳ Rollback THẤT BẠI\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_SVC_UPDATE_002 - Test ngoại lệ 1
        // Input  : PUT /services/999999 (ID không tồn tại)
        // Expected: result=0, msg='Service is not available'
        //           update() dòng 205-209: check isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_UPDATE_002';
        $desc   = 'Test ngoại lệ: PUT ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/services/999999', [
            'name'        => 'Test Not Found',
            'description' => 'Test',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_UPDATE_003 - Test ngoại lệ 2
        // Input  : PUT thiếu name (required field)
        // Expected: result=0, msg='Missing field: name'
        //           update() dòng 183-191: required_fields = ["name", "description"]
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_UPDATE_003';
        $desc   = 'Test ngoại lệ: PUT thiếu name → result=0 (required field)';
        $resp   = $this->http->request('PUT',
            API_BASE_URL . '/services/' . $serviceId,
            ['description' => 'Test thieu name'] // Không có name
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_UPDATE_004 - Test ngoại lệ 3
        // Input  : PUT thiếu description (required field)
        // Expected: result=0, msg='Missing field: description'
        //           update() dòng 183-191: required_fields = ["name", "description"]
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_UPDATE_004';
        $desc   = 'Test ngoại lệ: PUT thiếu description → result=0 (required field)';
        $resp   = $this->http->request('PUT',
            API_BASE_URL . '/services/' . $serviceId,
            ['name' => 'Test Thieu Description'] // Không có description
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_UPDATE_005 - Test ngoại lệ 4
        // Input  : MEMBER token, PUT /services/{id}
        // Expected: result=0, msg='You are not admin & you can't do this action !'
        //           update() dòng 169-173: chỉ admin mới cập nhật được
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_UPDATE_005';
        $desc   = 'Test ngoại lệ: MEMBER cập nhật dịch vụ → result=0 (không có quyền)';
        if ($this->memberToken) {
            $resp   = $this->memberHttp->request('PUT',
                API_BASE_URL . '/services/' . $serviceId,
                ['name' => 'Test Member Update', 'description' => 'Test']
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER cập nhật được (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có member token');
        }
    }


    // =========================================================================
    // NHÓM TEST 5: XÓA DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra DELETE /services/{id} - ServiceController::delete()
     *
     * Phân quyền: Chỉ admin
     * Ràng buộc :
     *   - ID=1 là default service, không xóa được
     *   - Không xóa được nếu còn booking liên quan
     *   - Không xóa được nếu còn doctor_and_service liên quan
     *
     * Test chuẩn   : Tạo service mới → Xóa → CheckDB
     * Test ngoại lệ: ID=1, ID không tồn tại, còn booking/doctor liên quan, MEMBER xóa
     */
    public function runDeleteTests(): void
    {
        $this->printGroupHeader("NHÓM 5: XÓA DỊCH VỤ (DELETE /services/{id})");


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_001 - Test chuẩn 1
        // Input  : Tạo service mới → DELETE /services/{id}
        // Expected: result=1, msg='Service is deleted successfully !'
        // CheckDB : Bản ghi không còn trong tn_services
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_DELETE_001';
        $desc      = 'Test chuẩn: Tạo service mới → Xóa → CheckDB xác minh đã xóa';
        $createdId = 0;

        try {
            // Tạo service mới để xóa
            $createResp = $this->http->request('POST', API_BASE_URL . '/services', [
                'name'        => 'Dich Vu Se Bi Xoa Test',
                'description' => 'Dich vu nay se bi xoa trong TC_SVC_DELETE_001',
            ]);
            $createBody = $createResp['body'];

            if (($createBody['result'] ?? 0) != 1) {
                $this->recordResult($testId, $desc, false,
                    'Không tạo được service: ' . ($createBody['msg'] ?? 'N/A')
                );
                return;
            }

            $createdId  = (int)($createBody['data']['id'] ?? 0);
            $deleteResp = $this->http->request('DELETE', API_BASE_URL . '/services/' . $createdId);
            $deleteBody = $deleteResp['body'];
            $passed     = ($deleteBody['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi đã xóa khỏi tn_services
            $checkMsg = '';
            if ($this->db->connected) {
                $afterDelete = $this->db->getServiceById($createdId);
                if ($passed && $afterDelete !== null) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: Bản ghi ID={$createdId} vẫn còn trong DB";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: Bản ghi ID={$createdId} đã xóa khỏi DB";
                    $createdId = 0; // Đã xóa thành công, không cần rollback
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$deleteBody['result']}, " . ($deleteBody['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Xóa nếu API thất bại nhưng bản ghi vẫn còn
            if ($createdId > 0) $this->db->rollbackDeleteService($createdId);
        }


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_002 - Test ngoại lệ 1
        // Input  : DELETE /services/1 (ID=1 là default service)
        // Expected: result=0, msg='This is the default Service & it can't be deleted !'
        //           delete() dòng 233-237: if(id == 1) → từ chối
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DELETE_002';
        $desc   = 'Test ngoại lệ: DELETE ID=1 (default service) → result=0';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/services/1');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (không xóa được ID=1), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_003 - Test ngoại lệ 2
        // Input  : DELETE /services/999999 (ID không tồn tại)
        // Expected: result=0, msg='Service is not available'
        //           delete() dòng 241-245: check isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DELETE_003';
        $desc   = 'Test ngoại lệ: DELETE ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/services/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_004 - Test ngoại lệ 3
        // Input  : DELETE service đang có booking liên quan
        // Expected: result=0, msg='This Service can't be deleted because there are X booking...'
        //           delete() dòng 249-257: kiểm tra TABLE_BOOKINGS.service_id
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DELETE_004';
        $desc   = 'Test ngoại lệ: DELETE service còn booking liên quan → result=0';
        // ID=1 thường có booking liên quan (là default service)
        // Tìm service có booking
        $svcWithBooking = null;
        $rows = $this->db->query("SELECT DISTINCT service_id FROM tn_booking LIMIT 1");
        if (!empty($rows)) {
            $svcWithBooking = (int)$rows[0]['service_id'];
        }
        if ($svcWithBooking) {
            $resp   = $this->http->request('DELETE', API_BASE_URL . '/services/' . $svcWithBooking);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → Xóa được service có booking (lỗi)"
            );
        } else {
            // Dùng service có doctor liên quan thay thế
            $this->recordResult($testId, $desc, false,
                'SKIP: Không tìm được service có booking (ngoài ID=1)'
            );
        }


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_005 - Test ngoại lệ 4
        // Input  : DELETE service đang có bác sĩ được gán vào
        // Expected: result=0, msg='This Service can't be deleted because there are X records assigned...'
        //           delete() dòng 261-267: kiểm tra TABLE_DOCTOR_AND_SERVICE.service_id
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_DELETE_005';
        $desc      = 'Test ngoại lệ: DELETE service còn bác sĩ liên quan → result=0';
        $createdId = 0;

        try {
            // Tạo service mới và gán bác sĩ vào để test
            $createResp = $this->http->request('POST', API_BASE_URL . '/services', [
                'name'        => 'Dich Vu Co Bac Si Test',
                'description' => 'Test TC_SVC_DELETE_005',
            ]);
            if (($createResp['body']['result'] ?? 0) == 1) {
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);
                $doctorId  = $this->db->getActiveDoctorId();

                if ($createdId > 0 && $doctorId) {
                    // Gán bác sĩ vào service
                    $this->http->request('POST',
                        API_BASE_URL . '/doctors-and-services/' . $createdId,
                        ['doctor_id' => $doctorId]
                    );
                    // Thử xóa service có bác sĩ liên quan
                    $deleteResp = $this->http->request('DELETE', API_BASE_URL . '/services/' . $createdId);
                    $deleteBody = $deleteResp['body'];
                    $passed     = ($deleteBody['result'] ?? 1) == 0;
                    $this->recordResult($testId, $desc, $passed,
                        $passed
                            ? "result=0, msg='{$deleteBody['msg']}'"
                            : "result=" . ($deleteBody['result'] ?? 'N/A') . " → Xóa được service có bác sĩ (lỗi)"
                    );
                } else {
                    $this->recordResult($testId, $desc, false, 'SKIP: Không tìm được bác sĩ để gán');
                }
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được service test');
            }
        } finally {
            // Rollback: Xóa bản ghi doctor_and_service và service test
            if ($createdId > 0) {
                $this->db->execute(
                    "DELETE FROM " . TABLE_DOCTOR_AND_SERVICE . " WHERE service_id = ?",
                    [$createdId]
                );
                $this->db->rollbackDeleteService($createdId);
            }
        }


        // ---------------------------------------------------------------
        // TC_SVC_DELETE_006 - Test ngoại lệ 5
        // Input  : MEMBER token, DELETE /services/{id}
        // Expected: result=0, msg='You are not admin & you can't do this action !'
        //           delete() dòng 225-229: chỉ admin mới xóa được
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_DELETE_006';
        $desc      = 'Test ngoại lệ: MEMBER xóa dịch vụ → result=0 (không có quyền)';
        $serviceId = $this->db->getAnyServiceId();
        if ($this->memberToken && $serviceId) {
            $resp   = $this->memberHttp->request('DELETE', API_BASE_URL . '/services/' . $serviceId);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER xóa được (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false,
                !$this->memberToken ? 'SKIP: Không có member token' : 'SKIP: Không có service'
            );
        }
    }


    // =========================================================================
    // NHÓM TEST 6: QUẢN LÝ BÁC SĨ - DỊCH VỤ
    // =========================================================================

    /**
     * Kiểm tra DoctorsAndServicesController (GET/POST/DELETE /doctors-and-services/{id})
     *
     * Phân quyền: Chỉ admin
     * Chú ý: {id} là service_id (không phải doctor_and_service_id) với GET và POST
     *        {id} là doctor_and_service_id với DELETE
     *
     * Test chuẩn   : Lấy danh sách bác sĩ theo service, thêm bác sĩ, xóa bác sĩ
     * Test ngoại lệ: Service/doctor không tồn tại, MEMBER thực hiện
     */
    public function runDoctorServiceTests(): void
    {
        $this->printGroupHeader("NHÓM 6: QUẢN LÝ BÁC SĨ - DỊCH VỤ (GET|POST|DELETE /doctors-and-services/{id})");

        $serviceId = $this->db->getAnyServiceId();
        $doctorId  = $this->db->getActiveDoctorId();


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_001 - Test chuẩn 1
        // Input  : GET /doctors-and-services/{service_id} với service_id tồn tại
        // Expected: result=1, có trường service{id,name,description} và data (danh sách bác sĩ)
        //           Mỗi phần tử có: doctor_and_service_id, id, name, email, speciality
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_001';
        if (!$serviceId) {
            $this->recordResult($testId, 'Test chuẩn: GET danh sách bác sĩ theo service', false,
                'SKIP: Không có service trong DB'
            );
        } else {
            $desc = "Test chuẩn: GET /doctors-and-services/{$serviceId} → result=1, có service info và data";
            $resp = $this->http->request('GET', API_BASE_URL . '/doctors-and-services/' . $serviceId);
            $body = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && isset($body['service']['id'])
                   && isset($body['service']['name'])
                   && isset($body['data'])
                   && is_array($body['data'])
                   && isset($body['quantity']);
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=1, service='{$body['service']['name']}', "
                      . count($body['data']) . " bác sĩ"
                    : "result={$body['result']}, thiếu trường bắt buộc"
            );
        }


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_002 - Test ngoại lệ 1
        // Input  : GET /doctors-and-services/999999 (service_id không tồn tại)
        // Expected: result=0, msg='Service is not available !'
        //           getAll() dòng 72-76: check isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_002';
        $desc   = 'Test ngoại lệ: GET service_id=999999 không tồn tại → result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/doctors-and-services/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_003 - Test chuẩn 2
        // Input  : POST /doctors-and-services/{service_id}, doctor_id hợp lệ
        // Expected: result=1, msg='Created successfully'
        // CheckDB : Bản ghi tồn tại trong tn_doctor_and_service
        // Rollback: Xóa bản ghi vừa tạo
        // ---------------------------------------------------------------
        $testId    = 'TC_SVC_DOCTOR_003';
        $desc      = 'Test chuẩn: Thêm bác sĩ vào dịch vụ → result=1, CheckDB, Rollback';
        $createdDsId = 0;

        try {
            if ($serviceId && $doctorId) {
                // Xóa cặp này nếu đã tồn tại trước khi test
                $this->db->execute(
                    "DELETE FROM " . TABLE_DOCTOR_AND_SERVICE
                    . " WHERE service_id = ? AND doctor_id = ?",
                    [$serviceId, $doctorId]
                );

                $resp   = $this->http->request('POST',
                    API_BASE_URL . '/doctors-and-services/' . $serviceId,
                    ['doctor_id' => $doctorId]
                );
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 0) == 1;

                // CheckDB: Xác minh bản ghi trong tn_doctor_and_service
                $checkMsg = '';
                if ($passed && $this->db->connected) {
                    $rows = $this->db->query(
                        "SELECT id FROM " . TABLE_DOCTOR_AND_SERVICE
                        . " WHERE service_id = ? AND doctor_id = ? ORDER BY id DESC LIMIT 1",
                        [$serviceId, $doctorId]
                    );
                    if (!empty($rows)) {
                        $createdDsId = (int)$rows[0]['id'];
                        $checkMsg    = "CheckDB OK: doctor_and_service_id={$createdDsId}, "
                            . "service_id={$serviceId}, doctor_id={$doctorId}";
                    } else {
                        $passed   = false;
                        $checkMsg = 'CheckDB THẤT BẠI: Không tìm thấy bản ghi trong tn_doctor_and_service';
                    }
                }
                $this->recordResult($testId, $desc, $passed,
                    $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
                );
            } else {
                $this->recordResult($testId, $desc, false,
                    'SKIP: Không có service hoặc bác sĩ để test'
                );
            }
        } finally {
            // Rollback: Luôn xóa bản ghi doctor_and_service vừa tạo
            if ($createdDsId > 0) {
                $this->db->rollbackDeleteDoctorAndService($createdDsId);
                $after = $this->db->getDoctorAndServiceById($createdDsId);
                echo Color::green("         ↳ Rollback " . ($after === null ? "OK" : "THẤT BẠI")
                    . ": doctor_and_service_id={$createdDsId}\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_004 - Test ngoại lệ 2
        // Input  : POST /doctors-and-services/{service_id}, doctor_id=999999 (không tồn tại)
        // Expected: result=0, msg='Doctor is not available'
        //           create() dòng 122-125: check Doctor isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_004';
        $desc   = 'Test ngoại lệ: Thêm doctor_id=999999 không tồn tại → result=0';
        if ($serviceId) {
            $resp   = $this->http->request('POST',
                API_BASE_URL . '/doctors-and-services/' . $serviceId,
                ['doctor_id' => 999999]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có service');
        }


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_005 - Test ngoại lệ 3
        // Input  : POST /doctors-and-services/{service_id}, thiếu doctor_id
        // Expected: result=0, msg='Doctor ID is required !'
        //           create() dòng 115-118: if(!$doctor_id) → lỗi
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_005';
        $desc   = 'Test ngoại lệ: Thiếu doctor_id khi thêm bác sĩ → result=0';
        if ($serviceId) {
            $resp   = $this->http->request('POST',
                API_BASE_URL . '/doctors-and-services/' . $serviceId,
                [] // Không có doctor_id
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có service');
        }


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_006 - Test chuẩn 3
        // Input  : Tạo cặp doctor-service → DELETE /doctors-and-services/{doctor_and_service_id}
        // Expected: result=1, msg='Deleted successfully'
        // CheckDB : Bản ghi không còn trong tn_doctor_and_service
        // ---------------------------------------------------------------
        $testId      = 'TC_SVC_DOCTOR_006';
        $desc        = 'Test chuẩn: Xóa bác sĩ khỏi dịch vụ → result=1, CheckDB';
        $createdDsId = 0;

        try {
            if ($serviceId && $doctorId) {
                // Xóa cặp nếu tồn tại rồi tạo lại để test
                $this->db->execute(
                    "DELETE FROM " . TABLE_DOCTOR_AND_SERVICE
                    . " WHERE service_id = ? AND doctor_id = ?",
                    [$serviceId, $doctorId]
                );
                // Tạo cặp mới
                $createResp = $this->http->request('POST',
                    API_BASE_URL . '/doctors-and-services/' . $serviceId,
                    ['doctor_id' => $doctorId]
                );
                if (($createResp['body']['result'] ?? 0) == 1) {
                    // Lấy ID vừa tạo
                    $rows = $this->db->query(
                        "SELECT id FROM " . TABLE_DOCTOR_AND_SERVICE
                        . " WHERE service_id = ? AND doctor_id = ? ORDER BY id DESC LIMIT 1",
                        [$serviceId, $doctorId]
                    );
                    $createdDsId = isset($rows[0]) ? (int)$rows[0]['id'] : 0;

                    if ($createdDsId > 0) {
                        // Xóa cặp vừa tạo
                        $deleteResp = $this->http->request('DELETE',
                            API_BASE_URL . '/doctors-and-services/' . $createdDsId
                        );
                        $deleteBody = $deleteResp['body'];
                        $passed     = ($deleteBody['result'] ?? 0) == 1;

                        // CheckDB: Xác minh đã xóa
                        $checkMsg = '';
                        if ($this->db->connected) {
                            $afterDelete = $this->db->getDoctorAndServiceById($createdDsId);
                            if ($passed && $afterDelete !== null) {
                                $passed   = false;
                                $checkMsg = "CheckDB THẤT BẠI: Bản ghi ID={$createdDsId} vẫn còn";
                            } elseif ($passed) {
                                $checkMsg    = "CheckDB OK: Bản ghi ID={$createdDsId} đã xóa";
                                $createdDsId = 0; // Đã xóa thành công
                            }
                        }
                        $this->recordResult($testId, $desc, $passed,
                            $passed ? $checkMsg : "result={$deleteBody['result']}, " . ($deleteBody['msg'] ?? $checkMsg)
                        );
                    } else {
                        $this->recordResult($testId, $desc, false, 'Không lấy được ID vừa tạo');
                    }
                } else {
                    $this->recordResult($testId, $desc, false, 'Không tạo được cặp doctor-service để test');
                }
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không có service hoặc bác sĩ');
            }
        } finally {
            if ($createdDsId > 0) $this->db->rollbackDeleteDoctorAndService($createdDsId);
        }


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_007 - Test ngoại lệ 4
        // Input  : DELETE /doctors-and-services/999999 (ID không tồn tại)
        // Expected: result=0, msg='DoctorAndService is not available !'
        //           delete() dòng 176-180: check isAvailable()
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_007';
        $desc   = 'Test ngoại lệ: DELETE doctor_and_service_id=999999 không tồn tại → result=0';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/doctors-and-services/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_SVC_DOCTOR_008 - Test ngoại lệ 5
        // Input  : MEMBER token, POST /doctors-and-services/{service_id}
        // Expected: result=0, msg='You are not admin & you can't do this action !'
        //           DoctorsAndServicesController::process() dòng 18-23: chỉ admin
        // ---------------------------------------------------------------
        $testId = 'TC_SVC_DOCTOR_008';
        $desc   = 'Test ngoại lệ: MEMBER thêm bác sĩ vào dịch vụ → result=0 (không có quyền)';
        if ($this->memberToken && $serviceId && $doctorId) {
            $resp   = $this->memberHttp->request('POST',
                API_BASE_URL . '/doctors-and-services/' . $serviceId,
                ['doctor_id' => $doctorId]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER thêm được (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có member token hoặc data');
        }
    }


    // =========================================================================
    // PHƯƠNG THỨC CHẠY CHÍNH
    // =========================================================================

    public function run(string $group = 'all'): void
    {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════╗\n"
            . "║   TEST MODULE DỊCH VỤ - UMBRELLA CORPORATION        ║\n"
            . "║   PHP Test Script - Kết quả hiển thị trên Terminal  ║\n"
            . "╚══════════════════════════════════════════════════════╝"
        )) . "\n";
        echo Color::yellow("  API URL   : " . API_BASE_URL) . "\n";
        echo Color::yellow("  Thời gian : " . date('Y-m-d H:i:s')) . "\n";
        echo Color::yellow("  Database  : " . DB_NAME . "@" . DB_HOST) . "\n";

        // Đăng nhập lấy token (không tính vào test case)
        $this->printGroupHeader("ĐĂNG NHẬP (không tính vào test case)");
        $this->doLogin();

        switch ($group) {
            case 'list':       $this->runListTests();          break;
            case 'create':     $this->runCreateTests();        break;
            case 'detail':     $this->runGetByIdTests();       break;
            case 'update':     $this->runUpdateTests();        break;
            case 'delete':     $this->runDeleteTests();        break;
            case 'doctor':     $this->runDoctorServiceTests(); break;
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runGetByIdTests();
                $this->runUpdateTests();
                $this->runDeleteTests();
                $this->runDoctorServiceTests();
                break;
        }

        $this->printSummary();
        $this->db->close();
    }
}


// =============================================================================
// ĐIỂM VÀO CHƯƠNG TRÌNH
// =============================================================================
$group  = $argv[1] ?? 'all';
$runner = new TestRunner();
$runner->run($group);