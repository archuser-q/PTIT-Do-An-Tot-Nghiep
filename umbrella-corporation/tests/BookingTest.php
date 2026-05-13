<?php
/**
 * =============================================================================
 * FILE:    BookingTest.php
 * PROJECT: Umbrella Corporation - Hệ thống quản lý phòng khám
 * MODULE:  Booking (Lịch đặt khám)
 * AUTHOR:  Test Engineer
 * DATE:    2026-05-11
 *
 * MÔ TẢ:
 *   Test script PHP cho module Booking, gọi trực tiếp API bằng cURL.
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
 *   GET    API/bookings          → BookingsController::getAll()   - Danh sách booking
 *   POST   API/bookings          → BookingsController::save()     - Tạo mới booking
 *   GET    API/bookings/{id}     → BookingController::getById()   - Chi tiết booking
 *
 *
 *
 * DATABASE: nextpost, bảng tn_booking
 * Schema: id, service_id, patient_id, booking_name, booking_phone, name,
 *         gender, birthday, address, reason, appointment_date, appointment_hour,
 *         status, create_at, update_at
 *
 * TRẠNG THÁI BOOKING (status):
 *   processing → verified  : Xác nhận booking, tạo appointment mới
 *   processing → cancelled : Hủy booking
 *   verified   → cancelled : Hủy booking đã xác nhận
 *
 * CÁCH CHẠY:
 *   php BookingTest.php              → Chạy tất cả
 *   php BookingTest.php list         → Danh sách booking
 *   php BookingTest.php create       → Tạo mới booking
 *   php BookingTest.php detail       → Chi tiết booking
 *
 *
 *   php BookingTest.php validation   → Validation dữ liệu
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
define('TABLE_BOOKING',   'tn_booking');
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
 * Bảng chính: tn_booking
 * Schema: id, service_id, patient_id, booking_name, booking_phone, name,
 *         gender, birthday, address, reason, appointment_date, appointment_hour,
 *         status, create_at, update_at
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

    /** Lấy bản ghi booking theo ID. Dùng cho CheckDB */
    public function getBookingById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_BOOKING . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lấy bản ghi booking mới nhất theo tên người đặt. Dùng cho CheckDB */
    public function getBookingByName(string $bookingName): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_BOOKING
            . " WHERE booking_name = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$bookingName]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ROLLBACK: Xóa booking vừa tạo trong test.
     * Gọi trong finally block để đảm bảo DB về trạng thái ban đầu.
     */
    public function rollbackDeleteBooking(int $id): void
    {
        if (!$this->connected) return;
        $this->execute(
            "DELETE FROM " . TABLE_BOOKING . " WHERE id = ?",
            [$id]
        );
    }

    /**
     * ROLLBACK: Khôi phục status booking về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật status.
     */
    public function rollbackRestoreStatus(int $id, string $originalStatus): void
    {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_BOOKING . " SET status = ? WHERE id = ?",
            [$originalStatus, $id]
        );
    }

    /**
     * ROLLBACK: Khôi phục booking_name về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật thông tin.
     */
    public function rollbackRestoreBookingName(int $id, string $originalName): void
    {
        if (!$this->connected) return;
        $this->execute(
            "UPDATE " . TABLE_BOOKING . " SET booking_name = ? WHERE id = ?",
            [$originalName, $id]
        );
    }

    /** Lấy ID booking đầu tiên có status='processing' */
    public function getFirstProcessingBookingId(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT id FROM " . TABLE_BOOKING
            . " WHERE status = 'processing' LIMIT 1"
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /** Lấy ID booking đầu tiên có status='verified' */
    public function getFirstVerifiedBookingId(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT id FROM " . TABLE_BOOKING
            . " WHERE status = 'verified' LIMIT 1"
        );
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /** Lấy ID booking bất kỳ (mới nhất) */
    public function getAnyBookingId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM " . TABLE_BOOKING . " ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }

    /** Lấy ID service đang tồn tại. Dùng để tạo booking test */
    public function getAnyServiceId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query("SELECT id FROM tn_services LIMIT 1");
        $row  = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
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
 * Lớp TestRunner chạy và quản lý tất cả test case module Booking.
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
    private ?int    $serviceId   = null;

    // Dữ liệu booking mẫu dùng chung trong các test tạo mới
    private array $sampleBookingData = [
        'patient_id'       => 1,
        'booking_name'     => 'Nguyen Van Test',
        'booking_phone'    => '0987654321',
        'name'             => 'Nguyen Van Benh Nhan',
        'gender'           => 0,
        'birthday'         => '1990-01-15',
        'address'          => 'Ha Noi',
        'reason'           => 'Dau dau chong mat test booking',
        'appointment_date' => '',   // Sẽ set trong doLogin
        'appointment_time' => '09:00',
    ];

    public function __construct()
    {
        $this->http       = new HttpClient();
        $this->memberHttp = new HttpClient();
        $this->db         = new DatabaseHelper();
        // Ngày hẹn = ngày mai để tránh trùng ngày hiện tại
        $this->sampleBookingData['appointment_date'] = date('Y-m-d', strtotime('+1 day'));
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
     * @param string $testId      ID test case (TC_BK_LIST_001)
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
            echo Color::yellow("  ⚠ Member doctor đăng nhập thất bại\n");
        }

        // Lấy service_id để dùng trong test tạo mới
        $this->serviceId = $this->db->getAnyServiceId();
        if ($this->serviceId) {
            echo Color::green("  ✓ Service ID={$this->serviceId} sẵn sàng\n");
        } else {
            echo Color::yellow("  ⚠ Không tìm được service (một số test sẽ skip)\n");
        }
    }


    // =========================================================================
    // NHÓM TEST 1: DANH SÁCH BOOKING
    // =========================================================================

    /**
     * Kiểm tra GET /bookings - BookingsController::getAll()
     *
     * Phân quyền: admin, supporter, member đều xem được
     * Bộ lọc: search, appointment_date, service_id, status, length, start, order
     *
     * Test chuẩn   : Lấy danh sách với các bộ lọc hợp lệ
     * Test ngoại lệ: Không có token
     *
     * Lỗi logic phát hiện (BUG-BK-001):
     *   BookingsController.php::getAll() - search filter dòng 84-91:
     *   $q->where(TABLE_PREFIX.TABLE_SPECIALITIES.".booking_name", ...)
     *   Sai bảng: dùng TABLE_SPECIALITIES thay vì TABLE_BOOKINGS
     *   → Tìm kiếm luôn trả về rỗng vì query sai tên bảng
     */
    public function runListTests(): void
    {
        $this->printGroupHeader("NHÓM 1: DANH SÁCH BOOKING (GET /bookings)");

        // ---------------------------------------------------------------
        // TC_BK_LIST_001 - Test chuẩn 1
        // Input  : GET /bookings (không bộ lọc), token admin hợp lệ
        // Expected: result=1, data là mảng, có trường quantity
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_001';
        $desc   = 'Test chuẩn: GET không bộ lọc → result=1, data là mảng, có quantity';
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings');
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
        // TC_BK_LIST_002 - Test chuẩn 2
        // Input  : GET /bookings?status=processing
        // Expected: result=1, tất cả bản ghi có status='processing'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_002';
        $desc   = "Test chuẩn: Lọc status=processing → tất cả bản ghi đúng status";
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings', ['status' => 'processing']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['status'] ?? '') !== 'processing') { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " booking, tất cả status='processing'"
                : "Có bản ghi không có status='processing'"
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_003 - Test chuẩn 3
        // Input  : GET /bookings?status=verified
        // Expected: result=1, tất cả bản ghi có status='verified'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_003';
        $desc   = "Test chuẩn: Lọc status=verified → tất cả bản ghi đúng status";
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings', ['status' => 'verified']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['status'] ?? '') !== 'verified') { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " booking, tất cả status='verified'"
                : "Có bản ghi không có status='verified'"
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_004 - Test chuẩn 4
        // Input  : GET /bookings?status=cancelled
        // Expected: result=1, tất cả bản ghi có status='cancelled'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_004';
        $desc   = "Test chuẩn: Lọc status=cancelled → tất cả bản ghi đúng status";
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings', ['status' => 'cancelled']);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['status'] ?? '') !== 'cancelled') { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " booking, tất cả status='cancelled'"
                : "Có bản ghi không có status='cancelled'"
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_005 - Test chuẩn 5
        // Input  : GET /bookings?length=5&start=0
        // Expected: result=1, trả về tối đa 5 bản ghi
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_005';
        $desc   = 'Test chuẩn: Phân trang length=5 → trả về tối đa 5 bản ghi';
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings', ['length' => 5, 'start' => 0]);
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 5;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về {$count} bản ghi (≤ 5)"
                : "Trả về {$count} bản ghi (mong đợi ≤ 5)"
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_006 - Test chuẩn 6
        // Input  : GET /bookings?appointment_date={ngày mai}
        // Expected: result=1, tất cả bản ghi có appointment_date = ngày mai
        // ---------------------------------------------------------------
        $testId   = 'TC_BK_LIST_006';
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $desc     = "Test chuẩn: Lọc appointment_date={$tomorrow} → đúng ngày";
        $resp     = $this->http->request('GET', API_BASE_URL . '/bookings', ['appointment_date' => $tomorrow]);
        $body     = $resp['body'];
        $passed   = ($body['result'] ?? 0) == 1;
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                if (($item['appointment_date'] ?? '') !== $tomorrow) { $passed = false; break; }
            }
        }
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " booking đúng ngày {$tomorrow}"
                : "Có bản ghi không thuộc ngày {$tomorrow}"
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_007 - Test ngoại lệ 1
        // Input  : GET /bookings (không có token)
        // Expected: result=0 hoặc HTTP 302 (từ chối truy cập)
        // ---------------------------------------------------------------
        $testId   = 'TC_BK_LIST_007';
        $desc     = 'Test ngoại lệ: Không có token → bị từ chối truy cập';
        $tempHttp = new HttpClient();
        $resp     = $tempHttp->request('GET', API_BASE_URL . '/bookings');
        $body     = $resp['body'];
        $passed   = ($body['result'] ?? 1) == 0
                 || $resp['status'] == 302
                 || $resp['status'] == 401;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "HTTP {$resp['status']}: Từ chối đúng"
                : "Mong đợi bị từ chối, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_LIST_008 - Test ngoại lệ 2 (phát hiện lỗi logic BUG-BK-001)
        // Input  : GET /bookings?search=Nguyen (tìm kiếm theo tên)
        // Expected: result=1, trả về booking có booking_name chứa 'Nguyen'
        // Thực tế : result=1 nhưng search không lọc đúng
        //           BookingsController.php::getAll() dòng 84-91:
        //           WHERE TABLE_SPECIALITIES.booking_name ... (SAI BẢNG)
        //           Đúng phải là: TABLE_BOOKINGS.booking_name
        // ---------------------------------------------------------------
        $testId = 'TC_BK_LIST_008';
        $desc   = 'Test ngoại lệ: Tìm kiếm search=Nguyen → kết quả phải lọc đúng theo tên';

        // Trước tiên tạo booking có tên 'Nguyen Van Test' để test
        $createdId = 0;
        try {
            if ($this->serviceId) {
                $createResp = $this->http->request('POST', API_BASE_URL . '/bookings', array_merge(
                    $this->sampleBookingData,
                    ['service_id' => $this->serviceId, 'booking_name' => 'Nguyen Van Test']
                ));
                $createdId = (int)($createResp['body']['data']['id'] ?? 0);
            }

            $resp  = $this->http->request('GET', API_BASE_URL . '/bookings', ['search' => 'Nguyen']);
            $body  = $resp['body'];
            $count = count($body['data'] ?? []);

            // Kiểm tra kết quả tìm kiếm có đúng không
            $searchWorking = true;
            if (!empty($body['data'])) {
                foreach ($body['data'] as $item) {
                    $nameMatch   = stripos($item['booking_name'] ?? '', 'Nguyen') !== false;
                    $reasonMatch = stripos($item['reason']       ?? '', 'Nguyen') !== false;
                    if (!$nameMatch && !$reasonMatch) {
                        $searchWorking = false;
                        break;
                    }
                }
            }
            $passed = ($body['result'] ?? 0) == 1 && $searchWorking;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=1, search hoạt động đúng, trả về {$count} booking"
                    : "result={$body['result']}, search không lọc đúng. "
                      . "Lỗi: BookingsController.php::getAll() dòng 84: "
                      . "WHERE TABLE_SPECIALITIES.booking_name (sai bảng, phải là TABLE_BOOKINGS)"
            );
        } finally {
            if ($createdId > 0) $this->db->rollbackDeleteBooking($createdId);
        }
    }


    // =========================================================================
    // NHÓM TEST 2: TẠO MỚI BOOKING
    // =========================================================================

    /**
     * Kiểm tra POST /bookings - BookingsController::save()
     *
     * Required fields: service_id, booking_name, booking_phone,
     *                  name, appointment_time, appointment_date
     * Default status : 'verified'
     * Phân quyền    : chỉ admin, supporter
     *
     * Test chuẩn   : Tạo booking đủ thông tin hợp lệ, CheckDB, Rollback
     * Test ngoại lệ: Thiếu field bắt buộc, validation sai, phân quyền
     */
    public function runCreateTests(): void
    {
        $this->printGroupHeader("NHÓM 2: TẠO MỚI BOOKING (POST /bookings)");

        if (!$this->serviceId) {
            echo Color::yellow("  [SKIP] Không tìm được service_id\n");
            return;
        }


        // ---------------------------------------------------------------
        // TC_BK_CREATE_001 - Test chuẩn 1
        // Input  : service_id hợp lệ, booking_name='Nguyen Van Test',
        //          booking_phone='0987654321', name='Nguyen Van Benh Nhan',
        //          gender=0, birthday='1990-01-15', address='Ha Noi',
        //          reason='Dau dau', appointment_date=ngày mai, appointment_time='09:00'
        // Expected: result=1, status='verified' (default)
        // CheckDB : Bản ghi tồn tại trong tn_booking với đúng thông tin
        // Rollback: Xóa bản ghi vừa tạo
        // ---------------------------------------------------------------
        $testId    = 'TC_BK_CREATE_001';
        $desc      = 'Test chuẩn: Tạo booking đủ thông tin hợp lệ → result=1, CheckDB, Rollback';
        $createdId = 0;

        try {
            $inputData = array_merge($this->sampleBookingData, [
                'service_id'   => $this->serviceId,
                'booking_name' => 'Nguyen Van Test',
            ]);
            $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi trong tn_booking
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $record = $this->db->getBookingByName('Nguyen Van Test');
                if (!$record) {
                    $passed   = false;
                    $checkMsg = 'CheckDB THẤT BẠI: Không tìm thấy bản ghi trong tn_booking';
                } else {
                    $createdId = (int)$record['id'];
                    // Xác minh từng trường quan trọng
                    $nameOk   = ($record['booking_name']  === 'Nguyen Van Test');
                    $phoneOk  = ($record['booking_phone'] === $inputData['booking_phone']);
                    $statusOk = ($record['status']        === 'verified'); // Default status
                    $svcOk    = ((int)$record['service_id'] === $this->serviceId);

                    if (!$nameOk || !$phoneOk || !$statusOk || !$svcOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . (!$nameOk   ? "booking_name sai; "  : "")
                            . (!$phoneOk  ? "booking_phone sai; " : "")
                            . (!$statusOk ? "status phải='verified'; " : "")
                            . (!$svcOk    ? "service_id sai"      : "");
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId}, "
                            . "booking_name='{$record['booking_name']}', "
                            . "status='{$record['status']}'";
                    }
                }
            }
            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        } finally {
            // Rollback: Luôn xóa bản ghi test
            if ($createdId > 0) {
                $this->db->rollbackDeleteBooking($createdId);
                $after = $this->db->getBookingById($createdId);
                if ($after === null) {
                    echo Color::green("         ↳ Rollback OK: Bản ghi ID={$createdId} đã xóa\n");
                } else {
                    echo Color::red("         ↳ Rollback THẤT BẠI: Bản ghi ID={$createdId} vẫn còn\n");
                }
            }
        }


        // ---------------------------------------------------------------
        // TC_BK_CREATE_002 - Test ngoại lệ 1
        // Input  : Thiếu service_id (required field)
        // Expected: result=0, msg='Missing field: service_id'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_002';
        $desc   = 'Test ngoại lệ: Thiếu service_id → result=0 (required field)';
        $inputData = array_merge($this->sampleBookingData, [
            // service_id bị bỏ qua
        ]);
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_CREATE_003 - Test ngoại lệ 2
        // Input  : Thiếu booking_name (required field)
        // Expected: result=0, msg='Missing field: booking_name'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_003';
        $desc   = 'Test ngoại lệ: Thiếu booking_name → result=0 (required field)';
        $inputData = array_merge($this->sampleBookingData, [
            'service_id' => $this->serviceId,
            // booking_name bị bỏ qua
        ]);
        unset($inputData['booking_name']);
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_CREATE_004 - Test ngoại lệ 3
        // Input  : Thiếu appointment_time (required field)
        // Expected: result=0, msg='Missing field: appointment_time'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_004';
        $desc   = 'Test ngoại lệ: Thiếu appointment_time → result=0 (required field)';
        $inputData = array_merge($this->sampleBookingData, [
            'service_id' => $this->serviceId,
        ]);
        unset($inputData['appointment_time']);
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_CREATE_005 - Test ngoại lệ 4
        // Input  : service_id=999999 (không tồn tại)
        // Expected: result=0, msg='Service is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_005';
        $desc   = 'Test ngoại lệ: service_id=999999 không tồn tại → result=0';
        $inputData = array_merge($this->sampleBookingData, [
            'service_id' => 999999,
        ]);
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_CREATE_006 - Test ngoại lệ 5
        // Input  : booking_phone='012345' (< 10 số)
        // Expected: result=0, msg='Booking number has at least 10 number !'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_006';
        $desc   = 'Test ngoại lệ: booking_phone < 10 số → result=0';
        $inputData = array_merge($this->sampleBookingData, [
            'service_id'    => $this->serviceId,
            'booking_phone' => '012345', // Chỉ 6 ký tự
        ]);
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings', $inputData);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_CREATE_007 - Test ngoại lệ 6
        // Input  : MEMBER token, POST /bookings (member không có quyền tạo)
        // Expected: result=0 (BookingsController::save() chỉ cho admin, supporter)
        // ---------------------------------------------------------------
        $testId = 'TC_BK_CREATE_007';
        $desc   = 'Test ngoại lệ: MEMBER tạo booking → result=0 (không có quyền)';
        if ($this->memberToken) {
            $inputData = array_merge($this->sampleBookingData, [
                'service_id'   => $this->serviceId,
                'booking_name' => 'Test Member Create',
            ]);
            $resp   = $this->memberHttp->request('POST', API_BASE_URL . '/bookings', $inputData);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER tạo được booking (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: Không có member token');
        }
    }


    // =========================================================================
    // NHÓM TEST 3: CHI TIẾT BOOKING
    // =========================================================================

    /**
     * Kiểm tra GET /bookings/{id} - BookingController::getById()
     *
     * Phân quyền: chỉ admin, supporter (MEMBER bị từ chối ngay từ process())
     *
     * Test chuẩn   : Lấy chi tiết booking tồn tại với đủ thông tin
     * Test ngoại lệ: ID không tồn tại, MEMBER truy cập
     */
    public function runGetByIdTests(): void
    {
        $this->printGroupHeader("NHÓM 3: CHI TIẾT BOOKING (GET /bookings/{id})");

        $bookingId = $this->db->getAnyBookingId();


        // ---------------------------------------------------------------
        // TC_BK_DETAIL_001 - Test chuẩn 1
        // Input  : GET /bookings/{id} với id tồn tại, token admin
        // Expected: result=1, data có đủ: id, booking_name, booking_phone,
        //           name, status, appointment_date, appointment_time, service{id,name}
        // ---------------------------------------------------------------
        $testId = 'TC_BK_DETAIL_001';
        if (!$bookingId) {
            $this->recordResult($testId, 'Test chuẩn: GET chi tiết hợp lệ', false,
                'SKIP: Không có booking trong DB'
            );
        } else {
            $desc = "Test chuẩn: GET /bookings/{$bookingId} → result=1, đủ thông tin";
            $resp = $this->http->request('GET', API_BASE_URL . '/bookings/' . $bookingId);
            $body = $resp['body'];
            // Kiểm tra các trường bắt buộc theo BookingController::getById()
            $passed = ($body['result'] ?? 0) == 1
                   && isset($body['data']['id'])
                   && isset($body['data']['booking_name'])
                   && isset($body['data']['booking_phone'])
                   && isset($body['data']['status'])
                   && isset($body['data']['appointment_date'])
                   && isset($body['data']['appointment_time'])
                   && isset($body['data']['service']['id']);
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=1, booking_name='{$body['data']['booking_name']}', "
                      . "status='{$body['data']['status']}'"
                    : "result={$body['result']}, thiếu trường bắt buộc"
            );
        }


        // ---------------------------------------------------------------
        // TC_BK_DETAIL_002 - Test ngoại lệ 1
        // Input  : GET /bookings/999999 (ID không tồn tại)
        // Expected: result=0, msg='Booking is not available'
        // ---------------------------------------------------------------
        $testId = 'TC_BK_DETAIL_002';
        $desc   = 'Test ngoại lệ: GET ID=999999 không tồn tại → result=0';
        $resp   = $this->http->request('GET', API_BASE_URL . '/bookings/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_DETAIL_003 - Test ngoại lệ 2
        // Input  : MEMBER token, GET /bookings/{id}
        // Expected: result=0 (BookingController::process() chặn MEMBER ngay đầu)
        // ---------------------------------------------------------------
        $testId = 'TC_BK_DETAIL_003';
        $desc   = 'Test ngoại lệ: MEMBER xem chi tiết booking → result=0 (không có quyền)';
        if ($this->memberToken && $bookingId) {
            $resp   = $this->memberHttp->request('GET', API_BASE_URL . '/bookings/' . $bookingId);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;
            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, MEMBER bị từ chối đúng"
                    : "result=" . ($body['result'] ?? 'N/A') . " → MEMBER xem được booking (lỗi phân quyền)"
            );
        } else {
            $this->recordResult($testId, $desc, false,
                !$this->memberToken ? 'SKIP: Không có member token' : 'SKIP: Không có booking'
            );
        }
    }


    // =========================================================================
    // NHÓM TEST 4: VALIDATION DỮ LIỆU
    // =========================================================================

    /**
     * Kiểm tra validation input trong POST/PUT /bookings
     * Tham chiếu: common.helper.php
     *   isVietnameseName(): chỉ chấp nhận chữ cái + khoảng trắng
     *   isNumber()        : chỉ chấp nhận [0-9]
     *   isBirthdayValid() : ngày sinh phải trước ngày hiện tại
     *   isAddress()       : chấp nhận chữ cái, số, khoảng trắng, dấu phẩy, dấu gạch ngang
     *   isAppointmentTimeValid(): kiểm tra khung giờ hợp lệ
     */
    public function runValidationTests(): void
    {
        $this->printGroupHeader("NHÓM 4: VALIDATION DỮ LIỆU");

        if (!$this->serviceId) {
            echo Color::yellow("  [SKIP] Không tìm được service_id\n");
            return;
        }


        // ---------------------------------------------------------------
        // TC_BK_VALID_001 - Test ngoại lệ 1
        // Input  : booking_name='T3st B00king @#!' (có số và ký tự đặc biệt)
        // Expected: result=0, msg chứa "Vietnamese name only has letters and space"
        //           isVietnameseName(): chỉ [a-zA-Z + tiếng Việt + khoảng trắng]
        // ---------------------------------------------------------------
        $testId = 'TC_BK_VALID_001';
        $desc   = 'Test ngoại lệ: booking_name có ký tự đặc biệt → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id'   => $this->serviceId,
                'booking_name' => 'T3st B00king @#!', // Có số + ký tự đặc biệt
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (booking_name không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_VALID_002 - Test ngoại lệ 2
        // Input  : booking_phone='abcdefghij' (chữ cái, không phải số)
        // Expected: result=0, msg chứa "not a valid phone number"
        //           isNumber(): chỉ chấp nhận [0-9]
        // ---------------------------------------------------------------
        $testId = 'TC_BK_VALID_002';
        $desc   = 'Test ngoại lệ: booking_phone chứa chữ cái → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id'    => $this->serviceId,
                'booking_phone' => 'abcdefghij', // Chữ cái
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (phone không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_VALID_003 - Test ngoại lệ 3
        // Input  : name='T3st Nam3!' (có số và ký tự đặc biệt)
        // Expected: result=0, msg chứa "Vietnamese name only has letters and space"
        //           isVietnameseName() áp dụng cho cả trường name (tên bệnh nhân)
        // ---------------------------------------------------------------
        $testId = 'TC_BK_VALID_003';
        $desc   = 'Test ngoại lệ: name bệnh nhân có ký tự đặc biệt → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id' => $this->serviceId,
                'name'       => 'T3st Nam3!', // Có số + ký tự đặc biệt
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (name không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_VALID_004 - Test ngoại lệ 4
        // Input  : birthday = ngày trong tương lai (+1 năm)
        // Expected: result=0, msg chứa "birthday is not valid"
        //           isBirthdayValid(): ngày sinh phải trước ngày hiện tại
        // ---------------------------------------------------------------
        $testId         = 'TC_BK_VALID_004';
        $desc           = 'Test ngoại lệ: birthday ở tương lai → result=0';
        $futureBirthday = date('Y-m-d', strtotime('+1 year'));
        $resp           = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id' => $this->serviceId,
                'birthday'   => $futureBirthday, // Ngày trong tương lai
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (birthday tương lai), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_VALID_005 - Test ngoại lệ 5
        // Input  : gender=5 (không phải 0 hoặc 1)
        // Expected: result=0, msg='Gender is not valid. There are 2 values: 0 is female & 1 is men'
        //           BookingsController::save() dòng 188: valid_gender = [0, 1]
        // ---------------------------------------------------------------
        $testId = 'TC_BK_VALID_005';
        $desc   = 'Test ngoại lệ: gender=5 (không hợp lệ) → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id' => $this->serviceId,
                'gender'     => 5, // Chỉ chấp nhận 0 hoặc 1
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (gender không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_BK_VALID_006 - Test ngoại lệ 6
        // Input  : address='Test@Address#Special!' (có ký tự đặc biệt @ # !)
        // Expected: result=0, msg='Address only accepts letters, space & number'
        //           isAddress(): chỉ chấp nhận [chữ cái, số, khoảng trắng, , -]
        // ---------------------------------------------------------------
        $testId = 'TC_BK_VALID_006';
        $desc   = 'Test ngoại lệ: address có ký tự đặc biệt (@#!) → result=0';
        $resp   = $this->http->request('POST', API_BASE_URL . '/bookings',
            array_merge($this->sampleBookingData, [
                'service_id' => $this->serviceId,
                'address'    => 'Test@Address#Special!', // Ký tự đặc biệt
            ])
        );
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;
        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0 (address không hợp lệ), nhận result=" . ($body['result'] ?? 'N/A')
        );
    }


    // =========================================================================
    // PHƯƠNG THỨC CHẠY CHÍNH
    // =========================================================================

    public function run(string $group = 'all'): void
    {
        echo "\n" . Color::bold(Color::cyan(
            "╔══════════════════════════════════════════════════════╗\n"
            . "║   TEST MODULE BOOKING - UMBRELLA CORPORATION        ║\n"
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


            case 'validation': $this->runValidationTests(); break;
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runGetByIdTests();
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