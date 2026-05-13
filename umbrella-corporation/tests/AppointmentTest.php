<?php
/**
 * =============================================================================
 * FILE:    AppointmentTest_Final.php
 * PROJECT: Umbrella Corporation - Hệ thống quản lý phòng khám
 * MODULE:  Lịch hẹn (Appointments)
 * AUTHOR:  Test Engineer
 * DATE:    2026-05-11
 *
 * MÔ TẢ:
 *   Test script PHP cho module Lịch hẹn, gọi trực tiếp API bằng cURL.
 *   Chạy hoàn toàn trên terminal, KHÔNG cần trình duyệt.
 *
 * QUY TRÌNH TEST (theo tài liệu yêu cầu):
 *   Bước 1: Với mỗi method, xây dựng test case với input/expected output rõ ràng
 *           - Test chuẩn: input hợp lệ, hệ thống xử lý đúng
 *           - Test ngoại lệ: input không hợp lệ, hệ thống trả lỗi đúng
 *   Bước 2: Gọi API, kiểm tra kết quả, CheckDB xác minh thay đổi
 *   Bước 3: Rollback trong finally block để DB luôn trở về trạng thái ban đầu
 *
 * CẤU TRÚC API (từ routes.inc.php):
 *   POST   API/login                → Đăng nhập
 *   GET    API/appointments         → Danh sách lịch hẹn
 *   POST   API/appointments         → Tạo mới lịch hẹn
 *   GET    API/appointments/{id}    → Chi tiết lịch hẹn
 *   PUT    API/appointments/{id}    → Cập nhật thông tin
 *   PATCH  API/appointments/{id}    → Cập nhật trạng thái
 *   DELETE API/appointments/{id}    → Xóa lịch hẹn
 *
 * DATABASE: nextpost, bảng tn_appointments
 *
 * CÁCH CHẠY:
 *   php AppointmentTest_Final.php              → Chạy tất cả
 *   php AppointmentTest_Final.php list         → Danh sách
 *   php AppointmentTest_Final.php create       → Tạo mới
 *   php AppointmentTest_Final.php detail       → Chi tiết
 *   php AppointmentTest_Final.php update       → Cập nhật
 *   php AppointmentTest_Final.php status       → Đổi trạng thái
 *   php AppointmentTest_Final.php delete       → Xóa
 *   php AppointmentTest_Final.php validation   → Validation
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
define('TABLE_APPOINTMENTS', 'tn_appointments');
define('TABLE_BOOKING',      'tn_booking');
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
 * Bảng chính: tn_appointments
 * Schema: id, booking_id, doctor_id, patient_id, patient_name, patient_birthday,
 *         patient_reason, patient_phone, numerical_order, position,
 *         appointment_time, date, status, create_at, update_at
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

    /** Lấy bản ghi lịch hẹn theo ID. Dùng cho CheckDB */
    public function getAppointmentById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_APPOINTMENTS . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lấy bản ghi lịch hẹn mới nhất theo tên bệnh nhân. Dùng cho CheckDB */
    public function getAppointmentByPatientName(string $name): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_APPOINTMENTS
            . " WHERE patient_name = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ROLLBACK: Xóa bản ghi lịch hẹn vừa tạo trong test.
     * Gọi trong finally block để đảm bảo DB về trạng thái ban đầu.
     */
    public function rollbackDeleteAppointment(int $id): void
    {
        if (!$this->connected) return;
        $this->execute(
            "DELETE FROM " . TABLE_APPOINTMENTS . " WHERE id = ?",
            [$id]
        );
    }

    /**
     * ROLLBACK: Khôi phục status lịch hẹn về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật status.
     */
    public function rollbackRestoreStatus(int $id, string $originalStatus): void
    {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_APPOINTMENTS . " SET status = ? WHERE id = ?",
            [$originalStatus, $id]
        );
    }

    /**
     * ROLLBACK: Khôi phục patient_name về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật tên bệnh nhân.
     */
    public function rollbackRestorePatientName(int $id, string $originalName): void
    {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_APPOINTMENTS . " SET patient_name = ? WHERE id = ?",
            [$originalName, $id]
        );
    }

    /** Lấy ID lịch hẹn đầu tiên có status='processing' hôm nay */
    public function getFirstProcessingAppointmentId(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT id FROM " . TABLE_APPOINTMENTS
            . " WHERE status = 'processing' AND date = ? LIMIT 1",
            [date('Y-m-d')]
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /** Lấy ID lịch hẹn bất kỳ (mới nhất) */
    public function getAnyAppointmentId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM " . TABLE_APPOINTMENTS . " ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /** Lấy ID bác sĩ active */
    public function getActiveDoctorId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM tn_doctors WHERE active = 1 AND role IN ('member','admin') LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Tạo booking mẫu với status chỉ định.
     * Dùng để chuẩn bị dữ liệu test kiểm tra booking validation.
     *
     * @param  string $status Trạng thái booking: 'cancelled'|'processing'|'done'
     * @return int    ID của booking vừa tạo, 0 nếu thất bại
     */
    public function createSampleBooking(string $status = 'cancelled'): int
    {
        if (!$this->connected) return 0;
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . TABLE_BOOKING
            . " (service_id, patient_id, booking_name, booking_phone, name, gender,"
            . "  birthday, address, reason, appointment_date, appointment_time,"
            . "  status, create_at, update_at)"
            . " VALUES (1, 2, 'Test Booking', '0987654321', 'Test Patient', 0,"
            . "  '1990-01-01', 'Ha Noi', 'Test reason', ?, '09:00', ?, NOW(), NOW())"
        );
        $stmt->execute([date('Y-m-d'), $status]);
        return (int)$this->pdo->lastInsertId();
    }

    /** ROLLBACK: Xóa booking test. Gọi trong finally block */
    public function rollbackDeleteBooking(int $id): void
    {
        $this->execute(
            "DELETE FROM " . TABLE_BOOKING . " WHERE id = ?",
            [$id]
        );
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
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
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
 * Lớp TestRunner chạy và quản lý tất cả test case.
 * Hiển thị kết quả PASS/FAIL trên terminal với màu sắc.
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

    private ?string $accessToken  = null;
    private ?string $memberToken  = null;
    private ?int    $testDoctorId = null;

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
     * @param string $testId      ID test case (TC_APT_LIST_001)
     * @param string $description Mô tả
     * @param bool   $passed      true = PASS, false = FAIL
     * @param string $message     Chi tiết thêm (nguyên nhân fail, CheckDB, Rollback)
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
            echo Color::yellow("  ⚠ Member doctor đăng nhập thất bại (test phân quyền sẽ skip)\n");
        }
    }


    // =========================================================================
    // NHÓM TEST 1: DANH SÁCH LỊCH HẸN
    // =========================================================================

    /**
     * Kiểm tra GET /appointments - AppointmentsController::getAll()
     *
     * Test chuẩn : Lấy danh sách với các bộ lọc hợp lệ
     * Test ngoại lệ: Không có token, từ khóa không tồn tại
     */
    public function runListTests(): void
    {
        $this->printGroupHeader("NHÓM 1: DANH SÁCH LỊCH HẸN (GET /appointments)");

        // ---------------------------------------------------------------
        // TC_APT_LIST_001 - Test chuẩn 1
        // Input  : GET /appointments (không có bộ lọc), token hợp lệ
        // Expected: result=1, có trường data (mảng), có trường quantity
        // ---------------------------------------------------------------
        $testId = 'TC_APT_LIST_001';
        $desc   = 'Test chuẩn: GET không bộ lọc → result=1, data là mảng, có quantity';
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=1, quantity={$body['quantity']}, data count=" . count($body['data'])
                : "result={$body['result']}, msg=" . ($body['msg'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_LIST_002 - Test chuẩn 2
        // Input  : GET /appointments?date={hôm nay}
        // Expected: result=1, tất cả bản ghi trả về có date = hôm nay
        // ---------------------------------------------------------------
        $testId = 'TC_APT_LIST_002';
        $today  = date('Y-m-d');
        $desc   = "Test chuẩn: Lọc date={$today} → tất cả bản ghi đúng ngày";
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments', ['date' => $today]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['date'] ?? '') !== $today) { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " lịch hẹn, tất cả đúng ngày {$today}"
                : "Có bản ghi không thuộc ngày {$today}"
        );


        // ---------------------------------------------------------------
        // TC_APT_LIST_003 - Test chuẩn 3
        // Input  : GET /appointments?status=processing
        // Expected: result=1, tất cả bản ghi có status='processing'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_LIST_003';
        $desc   = 'Test chuẩn: Lọc status=processing → tất cả bản ghi đúng status';
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments', ['status' => 'processing']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['status'] ?? '') !== 'processing') { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " lịch hẹn, tất cả status='processing'"
                : "Có bản ghi không có status='processing'"
        );


        // ---------------------------------------------------------------
        // TC_APT_LIST_004 - Test chuẩn 4
        // Input  : GET /appointments?status=done
        // Expected: result=1, tất cả bản ghi có status='done'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_LIST_004';
        $desc   = 'Test chuẩn: Lọc status=done → tất cả bản ghi đúng status';
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments', ['status' => 'done']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['status'] ?? '') !== 'done') { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " lịch hẹn, tất cả status='done'"
                : "Có bản ghi không có status='done'"
        );


        // ---------------------------------------------------------------
        // TC_APT_LIST_005 - Test chuẩn 5
        // Input  : GET /appointments?search=Nguyen&length=5&start=0
        // Expected: result=1, trả về tối đa 5 bản ghi
        // ---------------------------------------------------------------
        $testId = 'TC_APT_LIST_005';
        $desc   = 'Test chuẩn: Tìm kiếm + phân trang length=5 → tối đa 5 bản ghi';
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments',
            ['search' => 'Nguyen', 'length' => 5, 'start' => 0]
        );
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 5;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về {$count} bản ghi (≤ 5)"
                : "Trả về {$count} bản ghi (mong đợi ≤ 5)"
        );


        // ---------------------------------------------------------------
        // TC_APT_LIST_006 - Test ngoại lệ 1
        // Input  : GET /appointments (không có token)
        // Expected: result=0 hoặc HTTP 302/401 (từ chối truy cập)
        // ---------------------------------------------------------------
        $testId   = 'TC_APT_LIST_006';
        $desc     = 'Test ngoại lệ: Không có token → bị từ chối truy cập';
        $tempHttp = new HttpClient(); // Không set token
        $resp     = $tempHttp->request('GET', API_BASE_URL . '/appointments');
        $body     = $resp['body'];
        $passed   = ($body['result'] ?? 1) == 0
                 || $resp['status'] == 302
                 || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Từ chối đúng"
                : "Mong đợi bị từ chối, nhận result=" . ($body['result'] ?? 'N/A')
        );
    }


    // =========================================================================
    // NHÓM TEST 2: TẠO MỚI LỊCH HẸN
    // =========================================================================

    /**
     * Kiểm tra POST /appointments - AppointmentsController::newFlow()
     *
     * Test chuẩn   : Tạo lịch hẹn với dữ liệu hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: Thiếu field bắt buộc, sai doctor_id, booking sai status
     *
     * Lỗi logic phát hiện (BUG-001):
     *   AppointmentsController.php dòng 574:
     *     if( !$Booking->get("status") == "cancelled")
     *   PHP ưu tiên ! trước == nên:
     *     !$Booking->get("status") = !(non-empty-string) = false
     *     false == "cancelled"     = false → điều kiện NEVER TRUE
     *   Hệ quả: Booking với status=processing hoặc done cũng tạo được appointment
     */
    public function runCreateTests(): void
    {
        $this->printGroupHeader("NHÓM 2: TẠO MỚI LỊCH HẸN (POST /appointments)");

        $this->testDoctorId = $this->db->getActiveDoctorId();
        if (!$this->testDoctorId) {
            echo Color::yellow("  [SKIP] Không tìm được bác sĩ active trong DB\n");
            return;
        }


        // ---------------------------------------------------------------
        // TC_APT_CREATE_001 - Test chuẩn 1
        // Input  : doctor_id hợp lệ, patient_id=2, patient_name='Nguyen Van An',
        //          patient_birthday='1990-01-01', patient_reason='Dau dau',
        //          patient_phone='0987654321', status='processing'
        // Expected: result=1
        // CheckDB : Bản ghi tồn tại trong tn_appointments với đúng thông tin
        // Rollback: Xóa bản ghi vừa tạo
        // ---------------------------------------------------------------
        $testId          = 'TC_APT_CREATE_001';
        $desc            = 'Test chuẩn: Tạo lịch hẹn đủ thông tin hợp lệ → result=1, CheckDB, Rollback';
        $inputPatientName = 'Nguyen Van An';
        $inputDoctorId    = $this->testDoctorId;
        $inputPatientId   = 2;
        $inputPhone       = '0987654321';
        $inputStatus      = 'processing';
        $createdId        = 0;

        try {
            // Gọi API tạo lịch hẹn
            $resp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                'doctor_id'        => $inputDoctorId,
                'patient_id'       => $inputPatientId,
                'patient_name'     => $inputPatientName,
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Dau dau - test TC_APT_CREATE_001',
                'patient_phone'    => $inputPhone,
                'status'           => $inputStatus,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi trong tn_appointments
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $record = $this->db->getAppointmentByPatientName($inputPatientName);
                if (!$record) {
                    $passed   = false;
                    $checkMsg = 'CheckDB THẤT BẠI: Không tìm thấy bản ghi trong tn_appointments';
                } else {
                    $createdId = (int)$record['id'];
                    // Xác minh từng trường quan trọng
                    $nameOk   = ($record['patient_name']  === $inputPatientName);
                    $phoneOk  = ($record['patient_phone'] === $inputPhone);
                    $statusOk = ($record['status']        === $inputStatus);
                    $doctorOk = ((int)$record['doctor_id'] === $inputDoctorId);
                    if (!$nameOk || !$phoneOk || !$statusOk || !$doctorOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . (!$nameOk   ? "patient_name sai; " : "")
                            . (!$phoneOk  ? "patient_phone sai; " : "")
                            . (!$statusOk ? "status sai; "        : "")
                            . (!$doctorOk ? "doctor_id sai"       : "");
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId}, "
                            . "patient_name='{$record['patient_name']}', "
                            . "status='{$record['status']}'";
                    }
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn xóa bản ghi test (trong finally block)
            if ($createdId > 0) {
                $this->db->rollbackDeleteAppointment($createdId);
                $after = $this->db->getAppointmentById($createdId);
                if ($after === null) {
                    echo Color::green("         ↳ Rollback OK: Bản ghi ID={$createdId} đã xóa\n");
                } else {
                    echo Color::red("         ↳ Rollback THẤT BẠI: Bản ghi ID={$createdId} vẫn còn\n");
                }
            }
        }


        // ---------------------------------------------------------------
        // TC_APT_CREATE_002 - Test ngoại lệ 1
        // Input  : Thiếu patient_name (required field)
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_APT_CREATE_002';
        $desc   = 'Test ngoại lệ: Thiếu patient_name → result=0 (required field)';
        // Input
        $inputData = [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            // patient_name bị bỏ qua
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_CREATE_002',
        ];
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_CREATE_003 - Test ngoại lệ 2
        // Input  : Thiếu patient_reason (required field)
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_APT_CREATE_003';
        $desc   = 'Test ngoại lệ: Thiếu patient_reason → result=0 (required field)';
        $inputData = [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            'patient_name'     => 'Test Missing Reason',
            'patient_birthday' => '1990-01-01',
            // patient_reason bị bỏ qua
        ];
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_CREATE_004 - Test ngoại lệ 3
        // Input  : service_id=0 và doctor_id=0 (không cung cấp cả 2)
        // Expected: result=0 (newFlow yêu cầu ít nhất 1 trong 2)
        // ---------------------------------------------------------------
        $testId = 'TC_APT_CREATE_004';
        $desc   = 'Test ngoại lệ: service_id=0 và doctor_id=0 → result=0';
        $inputData = [
            'service_id'       => 0,
            'doctor_id'        => 0,
            'patient_name'     => 'Test No Doctor',
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_CREATE_004',
        ];
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_CREATE_005 - Test ngoại lệ 4
        // Input  : patient_phone='012345' (6 ký tự, < 10 số)
        // Expected: result=0 (strlen < 10 → lỗi validation)
        // ---------------------------------------------------------------
        $testId = 'TC_APT_CREATE_005';
        $desc   = 'Test ngoại lệ: Số điện thoại < 10 số → result=0';
        $inputData = [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            'patient_name'     => 'Test Short Phone',
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_CREATE_005',
            'patient_phone'    => '012345', // 6 ký tự
        ];
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_CREATE_006 - Test ngoại lệ 5 (phát hiện lỗi logic BUG-001)
        // Input  : booking_id của booking có status='processing'
        // Expected: result=0 (booking chưa cancelled không được dùng)
        // Thực tế : result=1 vì điều kiện check sai
        //           AppointmentsController.php dòng 574:
        //             if( !$Booking->get("status") == "cancelled")
        //           PHP: !string = false, false == "cancelled" = false → NEVER TRUE
        // ---------------------------------------------------------------
        $testId   = 'TC_APT_CREATE_006';
        $desc     = 'Test ngoại lệ: Booking status=processing tạo appointment → phải bị từ chối';
        $bookingId = $this->db->createSampleBooking('processing');
        $createdId = 0;

        try {
            if ($bookingId > 0) {
                $inputData = [
                    'doctor_id'        => 2,
                    'patient_id'       => 2,
                    'patient_name'     => 'Test Booking Processing',
                    'patient_birthday' => '1990-01-01',
                    'patient_reason'   => 'Test TC_APT_CREATE_006',
                    'booking_id'       => $bookingId,
                ];
                $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
                $body   = $resp['body'];
                $result = $body['result'] ?? 1;
                $passed = ($result == 0); // Đúng: phải từ chối

                if ($result == 1 && isset($body['data']['id'])) {
                    $createdId = (int)$body['data']['id'];
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, hệ thống từ chối đúng"
                        : "result={$result} → Booking processing vẫn tạo được appointment. "
                          . "Lỗi: AppointmentsController.php dòng 574: "
                          . "if(!\\$Booking->get(\"status\")==\"cancelled\") luôn = false"
                );
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được booking test');
            }
        } finally {
            // Rollback: Xóa appointment nếu đã được tạo do bug
            if ($createdId > 0) {
                $this->db->rollbackDeleteAppointment($createdId);
                echo Color::yellow("         ↳ Rollback: Đã xóa appointment ID={$createdId} tạo bởi bug\n");
            }
            // Rollback: Xóa booking test
            if ($bookingId > 0) $this->db->rollbackDeleteBooking($bookingId);
        }


        // ---------------------------------------------------------------
        // TC_APT_CREATE_007 - Test ngoại lệ 6 (phát hiện lỗi logic BUG-001)
        // Input  : booking_id của booking có status='done'
        // Expected: result=0 (booking đã done không được dùng)
        // Thực tế : result=1 (cùng lỗi logic tại dòng 574)
        // ---------------------------------------------------------------
        $testId    = 'TC_APT_CREATE_007';
        $desc      = 'Test ngoại lệ: Booking status=done tạo appointment → phải bị từ chối';
        $bookingId2 = $this->db->createSampleBooking('done');
        $createdId  = 0;

        try {
            if ($bookingId2 > 0) {
                $inputData = [
                    'doctor_id'        => 2,
                    'patient_id'       => 2,
                    'patient_name'     => 'Test Booking Done',
                    'patient_birthday' => '1990-01-01',
                    'patient_reason'   => 'Test TC_APT_CREATE_007',
                    'booking_id'       => $bookingId2,
                ];
                $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', $inputData);
                $body   = $resp['body'];
                $result = $body['result'] ?? 1;
                $passed = ($result == 0);

                if ($result == 1 && isset($body['data']['id'])) {
                    $createdId = (int)$body['data']['id'];
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, hệ thống từ chối đúng"
                        : "result={$result} → Booking done vẫn tạo được appointment. "
                          . "Cùng lỗi logic tại AppointmentsController.php dòng 574"
                );
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được booking test');
            }
        } finally {
            if ($createdId  > 0) $this->db->rollbackDeleteAppointment($createdId);
            if ($bookingId2 > 0) $this->db->rollbackDeleteBooking($bookingId2);
        }
    }


    // =========================================================================
    // NHÓM TEST 3: CHI TIẾT LỊCH HẸN
    // =========================================================================

    /**
     * Kiểm tra GET /appointments/{id} - AppointmentController::getById()
     *
     * Test chuẩn   : Lấy chi tiết lịch hẹn tồn tại với đủ thông tin
     * Test ngoại lệ: ID không tồn tại, MEMBER xem appointment của doctor khác
     *
     * Lỗi logic phát hiện (BUG-006):
     *   AppointmentController::getById() không kiểm tra phân quyền MEMBER.
     *   getAll() đã giới hạn MEMBER chỉ xem của mình (dòng 100-103),
     *   nhưng getById() thiếu check tương tự → MEMBER xem được của bất kỳ doctor
     */
    public function runGetByIdTests(): void
    {
        $this->printGroupHeader("NHÓM 3: CHI TIẾT LỊCH HẸN (GET /appointments/{id})");

        $appointmentId = $this->db->getAnyAppointmentId();


        // ---------------------------------------------------------------
        // TC_APT_DETAIL_001 - Test chuẩn 1
        // Input  : GET /appointments/{id} với id tồn tại, token hợp lệ
        // Expected: result=1, data có đầy đủ: id, patient_name, status,
        //           doctor{id,name,avatar}, speciality{id,name}, room{id,name}
        // ---------------------------------------------------------------
        $testId = 'TC_APT_DETAIL_001';
        if (!$appointmentId) {
            $this->recordResult($testId, 'Test chuẩn: GET chi tiết hợp lệ', false,
                'SKIP: Không có lịch hẹn trong DB'
            );
        } else {
            $desc = "Test chuẩn: GET /appointments/{$appointmentId} → result=1, đủ thông tin";
            $resp = $this->http->request('GET', API_BASE_URL . '/appointments/' . $appointmentId);
            $body = $resp['body'];
            // Kiểm tra các trường bắt buộc theo AppointmentController::getById()
            $passed = ($body['result'] ?? 0) == 1
                   && isset($body['data']['id'])
                   && isset($body['data']['patient_name'])
                   && isset($body['data']['status'])
                   && isset($body['data']['doctor']['id'])
                   && isset($body['data']['speciality']['id'])
                   && isset($body['data']['room']['id']);
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=1, patient_name='{$body['data']['patient_name']}', "
                      . "status='{$body['data']['status']}'"
                    : "result={$body['result']}, thiếu trường bắt buộc trong response"
            );
        }


        // ---------------------------------------------------------------
        // TC_APT_DETAIL_002 - Test ngoại lệ 1
        // Input  : GET /appointments/999999 (ID không tồn tại)
        // Expected: result=0, msg='Appointment is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_DETAIL_002';
        $desc   = 'Test ngoại lệ: GET ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/appointments/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_DETAIL_003 - Test ngoại lệ 2 (phát hiện lỗi logic BUG-006)
        // Input  : MEMBER token, GET /appointments/{id} của admin doctor (id=1)
        // Expected: result=0 (MEMBER chỉ được xem appointment của mình)
        // Thực tế : result=1 vì getById() thiếu kiểm tra phân quyền
        //           AppointmentController.php::getById() không có:
        //             if (role == "member" && doctor_id != AuthUser->id) → từ chối
        // ---------------------------------------------------------------
        $testId  = 'TC_APT_DETAIL_003';
        $desc    = 'Test ngoại lệ: MEMBER xem chi tiết appointment của admin → phải bị từ chối';
        $createdId = 0;

        try {
            if ($this->memberToken) {
                // Tạo lịch hẹn cho admin doctor (doctor_id=1) để member thử xem
                $createResp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                    'doctor_id'        => 1,
                    'patient_id'       => 2,
                    'patient_name'     => 'Test Phan Quyen Detail',
                    'patient_birthday' => '1990-01-01',
                    'patient_reason'   => 'Test TC_APT_DETAIL_003',
                ]);
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);

                if ($createdId > 0) {
                    // Member (doctor_id=2) thử xem appointment của admin (doctor_id=1)
                    $resp   = $this->memberHttp->request('GET',
                        API_BASE_URL . '/appointments/' . $createdId
                    );
                    $body   = $resp['body'];
                    $result = $body['result'] ?? 1;
                    $passed = ($result == 0); // Đúng: phải từ chối

                    $this->recordResult($testId, $desc, $passed,
                        $passed
                            ? "result=0, MEMBER bị từ chối đúng"
                            : "result={$result} → MEMBER xem được appointment của admin. "
                              . "Lỗi: AppointmentController.php::getById() thiếu kiểm tra "
                              . "phân quyền MEMBER (doctor_id != AuthUser->id)"
                    );
                } else {
                    $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được lịch hẹn test');
                }
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không có member token');
            }
        } finally {
            // Rollback: Xóa lịch hẹn test
            if ($createdId > 0) $this->db->rollbackDeleteAppointment($createdId);
        }
    }


    // =========================================================================
    // NHÓM TEST 4: CẬP NHẬT LỊCH HẸN
    // =========================================================================

    /**
     * Kiểm tra PUT /appointments/{id} - AppointmentController::update()
     *
     * Test chuẩn   : Cập nhật patient_name hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: ID không tồn tại, thiếu field bắt buộc
     *
     * Lỗi logic phát hiện (BUG-004):
     *   update() dòng 267: $status = Input::put("status");  // Lấy giá trị
     *   Nhưng trong save() (dòng 317-325): KHÔNG có ->set("status", $status)
     *   → Gửi status mới qua PUT trả về result=1 nhưng status trong DB không đổi
     *   → Muốn đổi status phải dùng PATCH (confirm endpoint riêng)
     */
    public function runUpdateTests(): void
    {
        $this->printGroupHeader("NHÓM 4: CẬP NHẬT LỊCH HẸN (PUT /appointments/{id})");

        // update() chỉ hoạt động với lịch hẹn có date = hôm nay
        $appointmentId = $this->db->getFirstProcessingAppointmentId();
        if (!$appointmentId) {
            echo Color::yellow("  [SKIP] Không có lịch hẹn processing hôm nay\n");
            return;
        }

        $originalRecord = $this->db->getAppointmentById($appointmentId);
        $originalName   = $originalRecord['patient_name']    ?? 'Benh Nhan Goc';
        $originalPhone  = $originalRecord['patient_phone']   ?? '0987654321';
        $originalStatus = $originalRecord['status']          ?? 'processing';
        $doctorId       = $originalRecord['doctor_id']       ?? $this->testDoctorId ?? 1;
        $patientId      = $originalRecord['patient_id']      ?? 1;


        // ---------------------------------------------------------------
        // TC_APT_UPDATE_001 - Test chuẩn 1
        // Input  : PUT /appointments/{id}, patient_name='Nguyen Van Sua',
        //          các field khác giữ nguyên giá trị gốc
        // Expected: result=1
        // CheckDB : patient_name trong DB = 'Nguyen Van Sua'
        // Rollback: Khôi phục patient_name về giá trị gốc
        // ---------------------------------------------------------------
        $testId  = 'TC_APT_UPDATE_001';
        $desc    = "Test chuẩn: Cập nhật patient_name ID={$appointmentId} → CheckDB → Rollback";
        $newName = 'Nguyen Van Sua';

        try {
            $resp = $this->http->request('PUT',
                API_BASE_URL . '/appointments/' . $appointmentId,
                [
                    'doctor_id'        => $doctorId,
                    'patient_id'       => $patientId,
                    'patient_name'     => $newName,
                    'patient_birthday' => $originalRecord['patient_birthday'] ?? '1990-01-01',
                    'patient_reason'   => $originalRecord['patient_reason']   ?? 'Test update',
                    'patient_phone'    => $originalPhone,
                ]
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh patient_name đã thay đổi trong tn_appointments
            $checkMsg = '';
            if ($this->db->connected) {
                $updatedRecord = $this->db->getAppointmentById($appointmentId);
                $nameInDb      = $updatedRecord['patient_name'] ?? '';
                if ($passed && $nameInDb !== $newName) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: DB patient_name='{$nameInDb}', mong đợi='{$newName}'";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: patient_name='{$nameInDb}' (đã cập nhật đúng)";
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn khôi phục patient_name về giá trị gốc
            $this->db->rollbackRestorePatientName($appointmentId, $originalName);
            $restoredRecord = $this->db->getAppointmentById($appointmentId);
            if (($restoredRecord['patient_name'] ?? '') === $originalName) {
                echo Color::green("         ↳ Rollback OK: patient_name khôi phục về '{$originalName}'\n");
            } else {
                echo Color::red("         ↳ Rollback THẤT BẠI: patient_name chưa được khôi phục\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_APT_UPDATE_002 - Test ngoại lệ 1
        // Input  : PUT /appointments/999999 (ID không tồn tại)
        // Expected: result=0, msg='Appointment is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_UPDATE_002';
        $desc   = 'Test ngoại lệ: PUT ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('PUT', API_BASE_URL . '/appointments/999999', [
            'doctor_id'        => 1,
            'patient_id'       => 1,
            'patient_name'     => 'Test Not Found',
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_UPDATE_002',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_UPDATE_003 - Test ngoại lệ 2
        // Input  : PUT thiếu patient_name (required field)
        // Expected: result=0, msg='Missing field: patient_name'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_UPDATE_003';
        $desc   = 'Test ngoại lệ: PUT thiếu patient_name → result=0 (required field)';
        $resp   = $this->http->request('PUT',
            API_BASE_URL . '/appointments/' . $appointmentId,
            [
                'doctor_id'        => $doctorId,
                'patient_id'       => $patientId,
                // patient_name bị bỏ qua
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Test TC_APT_UPDATE_003',
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
        // TC_APT_UPDATE_004 - Test ngoại lệ 3 (phát hiện lỗi logic BUG-004)
        // Input  : PUT /appointments/{id} với status='done' trong body
        // Expected: result=1 VÀ status trong DB phải = 'done' (nếu PUT thành công)
        // Thực tế : result=1 nhưng status trong DB vẫn = 'processing'
        //           AppointmentController.php::update() dòng 317-325:
        //             save() không có ->set("status", $status)
        // ---------------------------------------------------------------
        $testId = 'TC_APT_UPDATE_004';
        $desc   = 'Test ngoại lệ: PUT gửi status=done → CheckDB xác minh status có thay đổi';

        try {
            $resp = $this->http->request('PUT',
                API_BASE_URL . '/appointments/' . $appointmentId,
                [
                    'doctor_id'        => $doctorId,
                    'patient_id'       => $patientId,
                    'patient_name'     => $originalName,
                    'patient_birthday' => $originalRecord['patient_birthday'] ?? '1990-01-01',
                    'patient_reason'   => $originalRecord['patient_reason']   ?? 'Test',
                    'patient_phone'    => $originalPhone,
                    'status'           => 'done', // Gửi status mới
                ]
            );
            $body   = $resp['body'];
            $result = $body['result'] ?? 0;

            if ($result == 1 && $this->db->connected) {
                // CheckDB: Nếu PUT thành công thì status PHẢI được lưu
                $afterUpdate = $this->db->getAppointmentById($appointmentId);
                $statusInDb  = $afterUpdate['status'] ?? '';
                $passed      = ($statusInDb === 'done');
                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "CheckDB OK: status='done' được lưu đúng"
                        : "result=1 (PUT thành công) nhưng status trong DB='{$statusInDb}' thay vì 'done'. "
                          . "Lỗi: AppointmentController.php::update() save() thiếu ->set(\"status\", \$status)"
                );
            } else {
                $this->recordResult($testId, $desc, false,
                    "result=0, msg=" . ($body['msg'] ?? 'N/A')
                );
            }
        } finally {
            // Rollback: Khôi phục status gốc
            $this->db->rollbackRestoreStatus($appointmentId, $originalStatus);
        }
    }


    // =========================================================================
    // NHÓM TEST 5: CẬP NHẬT TRẠNG THÁI
    // =========================================================================

    /**
     * Kiểm tra PATCH /appointments/{id} - AppointmentController::confirm()
     * Logic: processing → done hoặc processing → cancelled
     *
     * Test chuẩn   : Đổi status hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: Status không hợp lệ, thiếu status, ID không tồn tại,
     *                đổi từ done (đã khóa)
     */
    public function runStatusTests(): void
    {
        $this->printGroupHeader("NHÓM 5: CẬP NHẬT TRẠNG THÁI (PATCH /appointments/{id})");

        $appointmentId = $this->db->getFirstProcessingAppointmentId();
        if (!$appointmentId) {
            echo Color::yellow("  [SKIP] Không có lịch hẹn processing hôm nay\n");
            return;
        }
        $originalRecord = $this->db->getAppointmentById($appointmentId);
        $originalStatus = $originalRecord['status'] ?? 'processing';


        // ---------------------------------------------------------------
        // TC_APT_STATUS_001 - Test chuẩn 1
        // Input  : PATCH /appointments/{id}, status='done'
        // Expected: result=1
        // CheckDB : status trong DB = 'done'
        // Rollback: Khôi phục status về 'processing'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_STATUS_001';
        $desc   = "Test chuẩn: PATCH ID={$appointmentId} processing → done → CheckDB → Rollback";

        try {
            $resp   = $this->http->request('PATCH',
                API_BASE_URL . '/appointments/' . $appointmentId,
                ['status' => 'done']
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh status = 'done' trong tn_appointments
            $checkMsg = '';
            if ($this->db->connected) {
                $updatedRecord = $this->db->getAppointmentById($appointmentId);
                $statusInDb    = $updatedRecord['status'] ?? '';
                if ($passed && $statusInDb !== 'done') {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: DB status='{$statusInDb}', mong đợi='done'";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: status='done' (đã cập nhật đúng)";
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn khôi phục status về processing
            $this->db->rollbackRestoreStatus($appointmentId, $originalStatus);
            $restored = $this->db->getAppointmentById($appointmentId);
            if (($restored['status'] ?? '') === $originalStatus) {
                echo Color::green("         ↳ Rollback OK: status khôi phục về '{$originalStatus}'\n");
            } else {
                echo Color::red("         ↳ Rollback THẤT BẠI\n");
            }
        }


        // ---------------------------------------------------------------
        // TC_APT_STATUS_002 - Test chuẩn 2
        // Input  : PATCH /appointments/{id}, status='cancelled'
        // Expected: result=1
        // CheckDB : status trong DB = 'cancelled'
        // Rollback: Khôi phục status về 'processing'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_STATUS_002';
        $desc   = "Test chuẩn: PATCH ID={$appointmentId} processing → cancelled → CheckDB → Rollback";

        try {
            $resp   = $this->http->request('PATCH',
                API_BASE_URL . '/appointments/' . $appointmentId,
                ['status' => 'cancelled']
            );
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh status = 'cancelled' trong tn_appointments
            $checkMsg = '';
            if ($this->db->connected) {
                $updatedRecord = $this->db->getAppointmentById($appointmentId);
                $statusInDb    = $updatedRecord['status'] ?? '';
                if ($passed && $statusInDb !== 'cancelled') {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: DB status='{$statusInDb}', mong đợi='cancelled'";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: status='cancelled' (đã cập nhật đúng)";
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn khôi phục status về processing
            $this->db->rollbackRestoreStatus($appointmentId, $originalStatus);
        }


        // ---------------------------------------------------------------
        // TC_APT_STATUS_003 - Test ngoại lệ 1
        // Input  : PATCH, status='invalid_xyz' (không trong danh sách hợp lệ)
        // Expected: result=0, msg chứa "not valid"
        //           confirm() valid_status = ["cancelled", "done"]
        // ---------------------------------------------------------------
        $testId = 'TC_APT_STATUS_003';
        $desc   = 'Test ngoại lệ: PATCH status không hợp lệ (invalid_xyz) → result=0';
        $resp   = $this->http->request('PATCH',
            API_BASE_URL . '/appointments/' . $appointmentId,
            ['status' => 'invalid_xyz']
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_STATUS_004 - Test ngoại lệ 2
        // Input  : PATCH không gửi status
        // Expected: result=0, msg='Missing new status'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_STATUS_004';
        $desc   = 'Test ngoại lệ: PATCH không gửi status → result=0 (Missing new status)';
        $resp   = $this->http->request('PATCH',
            API_BASE_URL . '/appointments/' . $appointmentId,
            [] // Không có status
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_STATUS_005 - Test ngoại lệ 3
        // Input  : PATCH /appointments/999999 (ID không tồn tại), status='done'
        // Expected: result=0, msg='Appointment is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_STATUS_005';
        $desc   = 'Test ngoại lệ: PATCH ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('PATCH',
            API_BASE_URL . '/appointments/999999',
            ['status' => 'done']
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_STATUS_006 - Test ngoại lệ 4
        // Input  : Lịch hẹn đã có status='done', PATCH status='cancelled'
        // Expected: result=0 (confirm() không cho đổi khi đã done/cancelled)
        //           confirm() dòng 385: invalid_status = ["cancelled", "done"]
        // ---------------------------------------------------------------
        $testId    = 'TC_APT_STATUS_006';
        $desc      = 'Test ngoại lệ: Lịch hẹn đã done, PATCH → cancelled → result=0';
        $createdId = 0;

        try {
            $createResp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                'doctor_id'        => 2,
                'patient_id'       => 2,
                'patient_name'     => 'Test Status Lock',
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Test TC_APT_STATUS_006',
            ]);
            if (($createResp['body']['result'] ?? 0) == 1) {
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);
                // Đổi sang done trước
                $this->http->request('PATCH',
                    API_BASE_URL . '/appointments/' . $createdId,
                    ['status' => 'done']
                );
                // Thử đổi tiếp từ done → cancelled
                $patchResp   = $this->http->request('PATCH',
                    API_BASE_URL . '/appointments/' . $createdId,
                    ['status' => 'cancelled']
                );
                $patchResult = $patchResp['body']['result'] ?? 1;
                $passed      = ($patchResult == 0);
                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, msg='{$patchResp['body']['msg']}'"
                        : "result={$patchResult} → Lịch hẹn done vẫn đổi được status"
                );
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được lịch hẹn test');
            }
        } finally {
            // Rollback: Xóa lịch hẹn test
            if ($createdId > 0) $this->db->rollbackDeleteAppointment($createdId);
        }
    }


    // =========================================================================
    // NHÓM TEST 6: XÓA LỊCH HẸN
    // =========================================================================

    /**
     * Kiểm tra DELETE /appointments/{id} - AppointmentController::delete()
     *
     * Test chuẩn   : Xóa lịch hẹn processing hợp lệ, CheckDB
     * Test ngoại lệ: ID không tồn tại, status=done, MEMBER xóa
     *
     * Lỗi logic phát hiện (BUG-002):
     *   delete() dòng 520: chỉ check status="done"
     *     if($Appointment->get("status") == "done") { không cho xóa }
     *   Thiếu check: status == "cancelled"
     *   → Lịch hẹn đã hủy vẫn xóa được → mất lịch sử
     *
     * Lỗi logic phát hiện (BUG-003):
     *   delete() dòng 500: thông báo lỗi phân quyền sai
     *     "You are {role} or supporter & you can't do this action !"
     *   Sai: supporter CÓ quyền xóa, không nên kèm trong thông báo
     */
    public function runDeleteTests(): void
    {
        $this->printGroupHeader("NHÓM 6: XÓA LỊCH HẸN (DELETE /appointments/{id})");

        if (!$this->testDoctorId) $this->testDoctorId = $this->db->getActiveDoctorId();


        // ---------------------------------------------------------------
        // TC_APT_DELETE_001 - Test chuẩn 1
        // Input  : Tạo lịch hẹn mới → DELETE /appointments/{id}
        // Expected: result=1
        // CheckDB : Bản ghi không còn tồn tại trong tn_appointments
        // ---------------------------------------------------------------
        $testId    = 'TC_APT_DELETE_001';
        $desc      = 'Test chuẩn: Tạo lịch hẹn → Xóa → CheckDB xác minh đã xóa';
        $createdId = 0;

        try {
            if (!$this->testDoctorId) {
                $this->recordResult($testId, $desc, false, 'Không tìm được bác sĩ để test');
                return;
            }

            $createResp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                'doctor_id'        => $this->testDoctorId,
                'patient_id'       => 2,
                'patient_name'     => 'Nguyen Van Xoa',
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Test TC_APT_DELETE_001',
                'patient_phone'    => '0987000111',
            ]);
            $createBody = $createResp['body'];

            if (($createBody['result'] ?? 0) != 1) {
                $this->recordResult($testId, $desc, false,
                    'Không tạo được lịch hẹn: ' . ($createBody['msg'] ?? 'N/A')
                );
                return;
            }

            $createdId  = (int)($createBody['data']['id'] ?? 0);
            $deleteResp = $this->http->request('DELETE', API_BASE_URL . '/appointments/' . $createdId);
            $deleteBody = $deleteResp['body'];
            $passed     = ($deleteBody['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi không còn trong tn_appointments
            $checkMsg = '';
            if ($this->db->connected) {
                $afterDelete = $this->db->getAppointmentById($createdId);
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
            if ($createdId > 0) $this->db->rollbackDeleteAppointment($createdId);
        }


        // ---------------------------------------------------------------
        // TC_APT_DELETE_002 - Test ngoại lệ 1
        // Input  : DELETE /appointments/999999 (ID không tồn tại)
        // Expected: result=0, msg='Appointment is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_DELETE_002';
        $desc   = 'Test ngoại lệ: DELETE ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('DELETE', API_BASE_URL . '/appointments/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_DELETE_003 - Test ngoại lệ 2
        // Input  : Lịch hẹn có status='done', DELETE
        // Expected: result=0, msg chứa "can not delete"
        //           delete() dòng 520: if(status == "done") → từ chối
        // ---------------------------------------------------------------
        $testId    = 'TC_APT_DELETE_003';
        $desc      = 'Test ngoại lệ: DELETE lịch hẹn status=done → result=0';
        $createdId = 0;

        try {
            $createResp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                'doctor_id'        => 2,
                'patient_id'       => 2,
                'patient_name'     => 'Test Xoa Done',
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Test TC_APT_DELETE_003',
            ]);
            if (($createResp['body']['result'] ?? 0) == 1) {
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);
                // Đổi sang done trước
                $this->http->request('PATCH',
                    API_BASE_URL . '/appointments/' . $createdId,
                    ['status' => 'done']
                );
                // Thử xóa lịch hẹn đã done
                $delResp   = $this->http->request('DELETE', API_BASE_URL . '/appointments/' . $createdId);
                $delResult = $delResp['body']['result'] ?? 1;
                $passed    = ($delResult == 0);
                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, msg='{$delResp['body']['msg']}'"
                        : "result={$delResult} → Lịch hẹn done vẫn xóa được"
                );
                if ($delResult == 0) {
                    // Xóa thủ công vì API từ chối xóa
                    $this->db->rollbackDeleteAppointment($createdId);
                    $createdId = 0;
                }
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được lịch hẹn');
            }
        } finally {
            if ($createdId > 0) $this->db->rollbackDeleteAppointment($createdId);
        }


        // ---------------------------------------------------------------
        // TC_APT_DELETE_004 - Test ngoại lệ 3 (phát hiện lỗi logic BUG-002)
        // Input  : Lịch hẹn có status='cancelled', DELETE
        // Expected: result=0 (cần giữ lịch sử, không cho xóa)
        // Thực tế : result=1 vì delete() thiếu check status='cancelled'
        //           AppointmentController.php::delete() dòng 520:
        //             if(status == "done") { không cho xóa }
        //             // Thiếu: if(status == "cancelled") { không cho xóa }
        // ---------------------------------------------------------------
        $testId    = 'TC_APT_DELETE_004';
        $desc      = 'Test ngoại lệ: DELETE lịch hẹn status=cancelled → phải bị từ chối';
        $createdId = 0;

        try {
            $createResp = $this->http->request('POST', API_BASE_URL . '/appointments', [
                'doctor_id'        => 2,
                'patient_id'       => 2,
                'patient_name'     => 'Test Xoa Cancelled Bug',
                'patient_birthday' => '1990-01-01',
                'patient_reason'   => 'Test TC_APT_DELETE_004',
            ]);
            if (($createResp['body']['result'] ?? 0) == 1) {
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);
                // Đổi sang cancelled trước
                $this->http->request('PATCH',
                    API_BASE_URL . '/appointments/' . $createdId,
                    ['status' => 'cancelled']
                );
                // Thử xóa lịch hẹn đã cancelled
                $delResp   = $this->http->request('DELETE', API_BASE_URL . '/appointments/' . $createdId);
                $delResult = $delResp['body']['result'] ?? 1;
                $passed    = ($delResult == 0); // Đúng: phải từ chối

                // CheckDB: Xác minh kết quả
                $afterDelete = $this->db->getAppointmentById($createdId);
                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, lịch hẹn cancelled được bảo vệ đúng"
                        : "result={$delResult} → Lịch hẹn cancelled bị xóa. "
                          . "Lỗi: AppointmentController.php::delete() dòng 520 "
                          . "thiếu check status='cancelled'"
                );
                if ($afterDelete === null && !$passed) {
                    echo Color::red("         ↳ CheckDB: Bản ghi đã bị xóa vĩnh viễn khỏi DB!\n");
                    $createdId = 0; // Đã bị xóa, không cần rollback
                } elseif ($afterDelete !== null) {
                    // Vẫn còn trong DB (bị từ chối đúng) → xóa thủ công
                    $this->db->rollbackDeleteAppointment($createdId);
                    $createdId = 0;
                }
            } else {
                $this->recordResult($testId, $desc, false, 'SKIP: Không tạo được lịch hẹn');
            }
        } finally {
            if ($createdId > 0) $this->db->rollbackDeleteAppointment($createdId);
        }


        // ---------------------------------------------------------------
        // TC_APT_DELETE_005 - Test ngoại lệ 4 (phát hiện lỗi logic BUG-003)
        // Input  : MEMBER token, DELETE /appointments/{id}
        // Expected: result=0 VÀ msg KHÔNG chứa "or supporter"
        //           delete() dòng 500: "You are {role} or supporter & you can't..."
        //           Sai: supporter CÓ quyền xóa, không nên đề cập
        // ---------------------------------------------------------------
        $testId       = 'TC_APT_DELETE_005';
        $desc         = 'Test ngoại lệ: MEMBER xóa → thông báo lỗi không đề cập "or supporter"';
        $processingId = $this->db->getFirstProcessingAppointmentId();

        if ($this->memberToken && $processingId) {
            $resp   = $this->memberHttp->request('DELETE',
                API_BASE_URL . '/appointments/' . $processingId
            );
            $body   = $resp['body'];
            $msg    = $body['msg'] ?? '';
            // BUG: msg = "You are member or supporter & you can't do this action!"
            $hasBugMsg = (strpos($msg, 'or supporter') !== false);
            $passed    = !$hasBugMsg; // PASS nếu không có "or supporter" trong msg
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "msg không đề cập 'or supporter'"
                    : "msg='{$msg}'. Lỗi: AppointmentController.php::delete() dòng 500: "
                      . "supporter CÓ quyền xóa nhưng lại xuất hiện trong thông báo từ chối"
            );

            // TC_APT_DELETE_006: Kiểm tra MEMBER bị từ chối (logic phân quyền đúng)
            $testId = 'TC_APT_DELETE_006';
            $desc   = 'Test ngoại lệ: MEMBER xóa appointment → result=0 (phân quyền đúng)';
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed ? "result=0, MEMBER bị từ chối đúng" :
                    "MEMBER vẫn xóa được - lỗi phân quyền nghiêm trọng!"
            );
        } else {
            $skip = !$this->memberToken ? 'Không có member token' : 'Không có lịch hẹn processing';
            $this->recordResult($testId, $desc, false, "SKIP: {$skip}");
            $this->recordResult('TC_APT_DELETE_006', 'Test ngoại lệ: MEMBER xóa → result=0', false, "SKIP: {$skip}");
        }
    }


    // =========================================================================
    // NHÓM TEST 7: VALIDATION DỮ LIỆU
    // =========================================================================

    /**
     * Kiểm tra validation input trong POST/PUT /appointments
     * Tham chiếu: common.helper.php
     *   isVietnameseName() : regex chỉ chấp nhận chữ cái + khoảng trắng
     *   isBirthdayValid()  : ngày sinh phải trước ngày hiện tại
     *   isNumber()         : chỉ chấp nhận [0-9]
     *
     * Lỗi logic phát hiện (BUG-005):
     *   LoginController.php::loginByDoctor():
     *   Đăng nhập sai password/email không tồn tại vẫn trả về result=1 + token
     *   → Lỗi bảo mật nghiêm trọng
     */
    public function runValidationTests(): void
    {
        $this->printGroupHeader("NHÓM 7: VALIDATION DỮ LIỆU");

        if (!$this->testDoctorId) {
            $this->testDoctorId = $this->db->getActiveDoctorId() ?? 1;
        }


        // ---------------------------------------------------------------
        // TC_APT_VALID_001 - Test ngoại lệ 1
        // Input  : patient_name='T3st P@tient #123!' (có số và ký tự đặc biệt)
        // Expected: result=0, msg chứa "Vietnamese name only has letters and space"
        //           isVietnameseName() regex: chỉ [a-zA-Z + tiếng Việt + khoảng trắng]
        // ---------------------------------------------------------------
        $testId = 'TC_APT_VALID_001';
        $desc   = 'Test ngoại lệ: patient_name có ký tự đặc biệt → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            'patient_name'     => 'T3st P@tient #123!', // Có số + ký tự đặc biệt
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_VALID_001',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (tên không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_VALID_002 - Test ngoại lệ 2
        // Input  : patient_phone='abcdefghij' (10 ký tự nhưng không phải số)
        // Expected: result=0, msg chứa "not a valid phone number"
        //           isNumber() kiểm tra: chỉ chứa [0-9]
        // ---------------------------------------------------------------
        $testId = 'TC_APT_VALID_002';
        $desc   = 'Test ngoại lệ: patient_phone chứa chữ cái → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            'patient_name'     => 'Test Phone Validation',
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_VALID_002',
            'patient_phone'    => 'abcdefghij', // Chữ cái, không phải số
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (SĐT không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_VALID_003 - Test ngoại lệ 3
        // Input  : doctor_id=999999 (không tồn tại trong tn_doctors)
        // Expected: result=0, msg='This doctor does not exist !'
        // ---------------------------------------------------------------
        $testId = 'TC_APT_VALID_003';
        $desc   = 'Test ngoại lệ: doctor_id=999999 không tồn tại → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/appointments', [
            'doctor_id'        => 999999,
            'patient_id'       => 2,
            'patient_name'     => 'Test Invalid Doctor',
            'patient_birthday' => '1990-01-01',
            'patient_reason'   => 'Test TC_APT_VALID_003',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (bác sĩ không tồn tại), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_VALID_004 - Test ngoại lệ 4
        // Input  : patient_birthday = ngày trong tương lai (+1 năm)
        // Expected: result=0, msg chứa "Birthday is not valid"
        //           isBirthdayValid(): ngày sinh phải nhỏ hơn ngày hiện tại
        // ---------------------------------------------------------------
        $testId         = 'TC_APT_VALID_004';
        $desc           = 'Test ngoại lệ: patient_birthday ở tương lai → result=0';
        $futureBirthday = date('Y-m-d', strtotime('+1 year'));
        $resp           = $this->http->request('POST', API_BASE_URL . '/appointments', [
            'doctor_id'        => $this->testDoctorId,
            'patient_id'       => 2,
            'patient_name'     => 'Test Future Birthday',
            'patient_birthday' => $futureBirthday, // Ngày trong tương lai
            'patient_reason'   => 'Test TC_APT_VALID_004',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (ngày sinh tương lai), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_APT_VALID_005 - Test ngoại lệ 5 (phát hiện lỗi logic BUG-005)
        // Input  : POST /login, email=ADMIN_EMAIL, password='sai_mat_khau_xyz'
        // Expected: result=0, accessToken rỗng
        // Thực tế : result=1, CÓ accessToken → Lỗi bảo mật nghiêm trọng
        //           LoginController.php::loginByDoctor() dòng 84-87:
        //             password_verify($password, $Doctor->get("password"))
        //             → Xác thực không hoạt động đúng
        // ---------------------------------------------------------------
        $testId   = 'TC_APT_VALID_005';
        $desc     = 'Test ngoại lệ: Đăng nhập password sai → result=0, không có accessToken';
        $tempHttp = new HttpClient(); // Không có token
        $resp     = $tempHttp->request('POST', API_BASE_URL . '/login', [
            'email'    => ADMIN_EMAIL,
            'password' => 'sai_mat_khau_xyz_999', // Password sai
            'type'     => 'doctor',
        ]);
        $body   = $resp['body'];
        $result = $body['result'] ?? 1;
        $token  = $body['accessToken'] ?? '';
        $passed = ($result == 0 && empty($token));
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, accessToken rỗng - xác thực đúng"
                : "result={$result}, accessToken=" . (empty($token) ? 'rỗng' : 'CÓ TOKEN - LỖI BẢO MẬT!'). ". "
                  . "Lỗi: LoginController.php::loginByDoctor() password_verify() không hoạt động đúng"
        );


        // ---------------------------------------------------------------
        // TC_APT_VALID_006 - Test ngoại lệ 6 (phát hiện lỗi logic BUG-005)
        // Input  : POST /login, email='khong.ton.tai@nowhere.xyz' (không tồn tại)
        // Expected: result=0, accessToken rỗng
        // Thực tế : result=1, CÓ accessToken (cùng lỗi)
        // ---------------------------------------------------------------
        $testId = 'TC_APT_VALID_006';
        $desc   = 'Test ngoại lệ: Đăng nhập email không tồn tại → result=0, không có accessToken';
        $resp   = $tempHttp->request('POST', API_BASE_URL . '/login', [
            'email'    => 'khong.ton.tai@nowhere.xyz', // Email không tồn tại
            'password' => '123456',
            'type'     => 'doctor',
        ]);
        $body   = $resp['body'];
        $result = $body['result'] ?? 1;
        $token  = $body['accessToken'] ?? '';
        $passed = ($result == 0 && empty($token));
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, accessToken rỗng - xác thực đúng"
                : "result={$result}, accessToken=" . (empty($token) ? 'rỗng' : 'CÓ TOKEN - LỖI BẢO MẬT!'). ". "
                  . "Lỗi: LoginController.php::loginByDoctor() không kiểm tra email tồn tại"
        );
    }


    // =========================================================================
    // PHƯƠNG THỨC CHẠY CHÍNH
    // =========================================================================

    public function run(string $group = 'all'): void
    {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════╗\n"
            . "║   TEST MODULE LỊCH HẸN - UMBRELLA CORPORATION       ║\n"
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
            case 'list':       $this->runListTests();       break;
            case 'create':     $this->runCreateTests();     break;
            case 'detail':     $this->runGetByIdTests();    break;
            case 'update':     $this->runUpdateTests();     break;
            case 'status':     $this->runStatusTests();     break;
            case 'delete':     $this->runDeleteTests();     break;
            case 'validation': $this->runValidationTests(); break;
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runGetByIdTests();
                $this->runUpdateTests();
                $this->runStatusTests();
                $this->runDeleteTests();
                $this->runValidationTests();
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