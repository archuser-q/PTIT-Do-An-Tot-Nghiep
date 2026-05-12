<?php
/**
 * =============================================================================
 * FILE:    RoomTest.php
 * PROJECT: Umbrella Corporation - Hệ thống quản lý phòng khám
 * MODULE:  Phòng khám (Rooms)
 *
 * MÔ TẢ:
 *   Test script PHP cho module Phòng khám, gọi trực tiếp API bằng cURL thuần.
 *   Chạy hoàn toàn trên terminal, KHÔNG cần trình duyệt, KHÔNG cần Guzzle.
 *
 * QUY TRÌNH TEST (theo tài liệu yêu cầu):
 *   Bước 1: Với mỗi method, xây dựng test case với input/expected output rõ ràng
 *           Chọn input đạt phủ cấp 2 (phủ hết các nhánh trong source code).
 *   Bước 2: Gọi API, kiểm tra kết quả, CheckDB xác minh thay đổi.
 *   Bước 3: Rollback trong finally block để DB luôn trở về trạng thái ban đầu.
 *
 * CẤU TRÚC API:
 *   POST   /api/login           → Đăng nhập (bắt buộc trước khi test Room/Rooms)
 *   GET    /api/rooms           → Danh sách phòng (RoomsController::getAll)
 *   POST   /api/rooms           → Tạo phòng mới  (RoomsController::save)
 *   GET    /api/room/{id}       → Chi tiết phòng  (RoomController::getById)
 *   PUT    /api/room/{id}       → Cập nhật phòng  (RoomController::update)
 *   DELETE /api/room/{id}       → Xóa phòng       (RoomController::delete)
 *
 * DATABASE: nextpost, bảng tn_rooms, tn_doctors
 *
 * CÁCH CHẠY:
 *   php RoomTest.php              → Chạy tất cả nhóm
 *   php RoomTest.php list         → Danh sách phòng
 *   php RoomTest.php create       → Tạo phòng mới
 *   php RoomTest.php detail       → Chi tiết phòng
 *   php RoomTest.php update       → Cập nhật phòng
 *   php RoomTest.php delete       → Xóa phòng
 * =============================================================================
 */

// =============================================================================
// CẤU HÌNH - Chỉnh sửa nếu môi trường khác
// =============================================================================
define('API_BASE_URL',   'http://localhost:8080/PTIT-Do-An-Tot-Nghiep/api');
define('ADMIN_EMAIL',    'phongkaster@gmail.com');
define('ADMIN_PASSWORD', '123456');
define('DB_HOST',        '127.0.0.1');
define('DB_PORT',        3306);
define('DB_NAME',        'nextpost');
define('DB_USER',        'root');
define('DB_PASSWORD',    '');
define('TABLE_ROOMS',    'tn_rooms');
define('TABLE_DOCTORS',  'tn_doctors');
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
// Bảng chính: tn_rooms
// Schema: id, name, location
// =============================================================================
class DatabaseHelper
{
    private ?PDO $pdo      = null;
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
    // Thao tác trên bảng tn_rooms
    // ------------------------------------------------------------------

    /**
     * Lấy bản ghi phòng theo ID.
     * Dùng cho CheckDB sau khi tạo / cập nhật / xóa.
     */
    public function getRoomById(int $id): ?array
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . TABLE_ROOMS . " WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Đếm tổng số phòng trong DB.
     * Dùng cho CheckDB trước/sau thao tác tạo/xóa.
     */
    public function countRooms(): int
    {
        if (!$this->connected) return -1;
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM " . TABLE_ROOMS);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy bất kỳ phòng nào không phải ID=1 và không có doctor.
     * Dùng để tìm phòng test có thể xóa được.
     */
    public function getDeletableRoomId(): ?int
    {
        if (!$this->connected) return null;
        // Tìm room không có doctor và id != 1
        $rows = $this->query(
            "SELECT r.id FROM " . TABLE_ROOMS . " r
             LEFT JOIN " . TABLE_DOCTORS . " d ON d.room_id = r.id
             WHERE r.id != 1
             GROUP BY r.id
             HAVING COUNT(d.id) = 0
             LIMIT 1"
        );
        return isset($rows[0]) ? (int) $rows[0]['id'] : null;
    }

    /**
     * Lấy ID phòng đang có doctor (dùng để test xóa thất bại).
     */
    public function getRoomIdWithDoctor(): ?int
    {
        if (!$this->connected) return null;
        $rows = $this->query(
            "SELECT room_id FROM " . TABLE_DOCTORS . "
             WHERE room_id IS NOT NULL AND room_id != 0
             LIMIT 1"
        );
        return isset($rows[0]) ? (int) $rows[0]['room_id'] : null;
    }

    /**
     * Lấy ID bất kỳ một phòng tồn tại (dùng cho test getById).
     */
    public function getAnyRoomId(): ?int
    {
        if (!$this->connected) return null;
        $stmt = $this->pdo->query(
            "SELECT id FROM " . TABLE_ROOMS . " ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    // ------------------------------------------------------------------
    // ROLLBACK helpers
    // ------------------------------------------------------------------

    /**
     * ROLLBACK: Xóa phòng vừa tạo trong test.
     * Gọi trong finally block để đảm bảo DB về trạng thái ban đầu.
     */
    public function rollbackDeleteRoom(int $id): void
    {
        if (!$this->connected || $id <= 0) return;
        $this->execute(
            "DELETE FROM " . TABLE_ROOMS . " WHERE id = ?",
            [$id]
        );
    }

    /**
     * ROLLBACK: Khôi phục name và location của phòng về giá trị ban đầu.
     * Gọi trong finally block sau test cập nhật phòng.
     */
    public function rollbackRestoreRoom(int $id, string $originalName, string $originalLocation): void
    {
        if (!$this->connected || $id <= 0) return;
        $this->execute(
            "UPDATE " . TABLE_ROOMS . " SET name = ?, location = ? WHERE id = ?",
            [$originalName, $originalLocation, $id]
        );
    }

    public function close(): void { $this->pdo = null; }
}


// =============================================================================
// CLASS: HttpClient - Gọi API bằng cURL thuần PHP
//
// API dùng header: Authorization: JWT {token}  (theo AppointmentTest_Final.php)
// =============================================================================
class HttpClient
{
    private ?string $accessToken = null;

    public function setToken(string $token): void { $this->accessToken = $token; }
    public function clearToken(): void            { $this->accessToken = null; }

    /**
     * Thực hiện HTTP request bằng cURL.
     *
     * @param  string $method  GET / POST / PUT / DELETE
     * @param  string $url     URL đầy đủ của API endpoint
     * @param  array  $data    Dữ liệu gửi kèm (query string hoặc body)
     * @return array           ['status' => int, 'body' => array]
     */
    public function request(string $method, string $url, array $data = []): array
    {
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
            $parsed = ['result' => 0, 'msg' => 'Invalid JSON: ' . substr($responseBody, 0, 200)];
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

    /** HTTP client KHÔNG có token (dùng test trường hợp chưa login) */
    private HttpClient $unauthHttp;

    private DatabaseHelper $db;

    /** Access token admin sau khi login */
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->http       = new HttpClient();
        $this->unauthHttp = new HttpClient(); // Không gọi setToken
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
    // @param string $testId      ID test case (TC_ROOM_LIST_001)
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
    // ĐĂNG NHẬP - Bắt buộc trước khi test Room / Rooms
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
    // NHÓM TEST 1: DANH SÁCH PHÒNG
    // Kiểm tra GET /api/rooms → RoomsController::getAll()
    //
    // Các nhánh cần phủ:
    //   - Không có token    → bị redirect (nhánh !$AuthUser)
    //   - Không phải admin  → lỗi phân quyền (nhánh role != "admin")
    //   - Có token admin    → thành công (nhánh thành công)
    //   - Filter search     → nhánh $search_query
    //   - Filter order      → nhánh $order
    //   - Filter length     → nhánh limit/offset
    // =========================================================================
    public function runListTests(): void
    {
        $this->printGroupHeader("NHÓM 1: DANH SÁCH PHÒNG (GET /api/rooms)");


        // ---------------------------------------------------------------
        // TC_ROOM_LIST_001 - Test ngoại lệ: Không có token
        // Nhánh   : process() → !$AuthUser → header Location redirect
        // Input   : GET /api/rooms không có Authorization header
        // Expected: HTTP 302 hoặc result=0 (từ chối truy cập)
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_LIST_001';
        $desc   = 'Test ngoại lệ: Không có token → bị từ chối (redirect hoặc result=0)';

        $resp   = $this->unauthHttp->request('GET', API_BASE_URL . '/rooms');
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
        // TC_ROOM_LIST_002 - Test chuẩn: Admin lấy tất cả phòng
        // Nhánh   : getAll() → query thành công → trả data + quantity
        // Input   : GET /api/rooms với token admin, không có filter
        // Expected: result=1, data là mảng, quantity là số nguyên
        // CheckDB : quantity trong response phải khớp COUNT(*) trong DB
        // ---------------------------------------------------------------
        $testId      = 'TC_ROOM_LIST_002';
        $desc        = 'Test chuẩn: Admin GET /api/rooms không filter → result=1, có data + quantity';
        $dbRoomCount = $this->db->countRooms(); // CheckDB: đếm trước

        $resp   = $this->http->request('GET', API_BASE_URL . '/rooms');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1
               && isset($body['data'])
               && is_array($body['data'])
               && isset($body['quantity']);

        // CheckDB: quantity phải khớp số bản ghi trong DB
        if ($passed && $dbRoomCount >= 0) {
            $quantityMatch = ((int)$body['quantity'] === $dbRoomCount);
            if (!$quantityMatch) {
                $passed = false;
            }
            $checkMsg = $quantityMatch
                ? "CheckDB OK: quantity={$body['quantity']} khớp DB ({$dbRoomCount} bản ghi)"
                : "CheckDB THẤT BẠI: quantity={$body['quantity']}, DB={$dbRoomCount}";
        } else {
            $checkMsg = "result=1, quantity={$body['quantity']}, data count=" . count($body['data'] ?? []);
        }

        $this->recordResult($testId, $desc, $passed,
            $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
        );


        // ---------------------------------------------------------------
        // TC_ROOM_LIST_003 - Test chuẩn: Filter theo search
        // Nhánh   : getAll() → $search_query → WHERE name LIKE hoặc location LIKE
        // Input   : GET /api/rooms?search={từ_khóa}
        // Expected: result=1, tất cả bản ghi trả về có name hoặc location chứa từ khóa
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_LIST_003';

        // Lấy tên phòng đầu tiên trong DB để dùng làm từ khóa tìm kiếm
        $firstRoom   = $this->db->query("SELECT name FROM " . TABLE_ROOMS . " LIMIT 1");
        $searchQuery = isset($firstRoom[0]) ? substr($firstRoom[0]['name'], 0, 3) : 'A';
        $desc        = "Test chuẩn: Filter search='{$searchQuery}' → result=1, dữ liệu khớp từ khóa";

        $resp   = $this->http->request('GET', API_BASE_URL . '/rooms', ['search' => $searchQuery]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;

        // Xác minh mỗi bản ghi trả về phải chứa từ khóa trong name hoặc location
        if ($passed && !empty($body['data'])) {
            foreach ($body['data'] as $item) {
                $nameMatch     = stripos($item['name']     ?? '', $searchQuery) !== false;
                $locationMatch = stripos($item['location'] ?? '', $searchQuery) !== false;
                if (!$nameMatch && !$locationMatch) {
                    $passed = false;
                    break;
                }
            }
        }

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " phòng, tất cả khớp từ khóa '{$searchQuery}'"
                : "Có bản ghi không chứa từ khóa '{$searchQuery}' trong name hoặc location"
        );


        // ---------------------------------------------------------------
        // TC_ROOM_LIST_004 - Test chuẩn: Phân trang length + start
        // Nhánh   : getAll() → $query->limit($length)->offset($start)
        // Input   : GET /api/rooms?length=2&start=0
        // Expected: result=1, số bản ghi trả về <= 2
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_LIST_004';
        $desc   = 'Test chuẩn: Phân trang length=2, start=0 → tối đa 2 bản ghi';

        $resp   = $this->http->request('GET', API_BASE_URL . '/rooms', [
            'length' => 2,
            'start'  => 0,
        ]);
        $body   = $resp['body'];
        $count  = count($body['data'] ?? []);
        $passed = ($body['result'] ?? 0) == 1 && $count <= 2;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về {$count} bản ghi (≤ 2) - phân trang đúng"
                : "Trả về {$count} bản ghi, mong đợi ≤ 2"
        );


        // ---------------------------------------------------------------
        // TC_ROOM_LIST_005 - Test chuẩn: Sắp xếp theo name ASC
        // Nhánh   : getAll() → $order["column"] và $order["dir"] hợp lệ
        // Input   : GET /api/rooms?order[column]=name&order[dir]=asc
        // Expected: result=1, thứ tự name tăng dần
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_LIST_005';
        $desc   = 'Test chuẩn: Sắp xếp order[column]=name, order[dir]=asc → thứ tự đúng';

        $resp   = $this->http->request('GET', API_BASE_URL . '/rooms', [
            'order' => ['column' => 'name', 'dir' => 'asc'],
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 0) == 1;

        // Xác minh thứ tự name tăng dần
        if ($passed && count($body['data'] ?? []) > 1) {
            $names = array_column($body['data'], 'name');
            $sorted = $names;
            sort($sorted);
            if ($names !== $sorted) {
                $passed = false;
            }
        }

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "Trả về " . count($body['data'] ?? []) . " phòng, thứ tự name đúng ASC"
                : "Thứ tự name không đúng ASC"
        );
    }


    // =========================================================================
    // NHÓM TEST 2: TẠO PHÒNG MỚI
    // Kiểm tra POST /api/rooms → RoomsController::save()
    //
    // Các nhánh cần phủ:
    //   - Không có token      → bị redirect
    //   - Thiếu field name    → lỗi missing field
    //   - Thiếu field location → lỗi missing field
    //   - Trùng name+location → lỗi duplicate
    //   - Dữ liệu hợp lệ      → tạo thành công
    // =========================================================================
    public function runCreateTests(): void
    {
        $this->printGroupHeader("NHÓM 2: TẠO PHÒNG MỚI (POST /api/rooms)");


        // ---------------------------------------------------------------
        // TC_ROOM_CREATE_001 - Test ngoại lệ: Thiếu field "name"
        // Nhánh   : save() → !Input::post("name") → jsonecho lỗi
        // Input   : POST chỉ có location, thiếu name
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_CREATE_001';
        $desc   = 'Test ngoại lệ: Thiếu field "name" → result=0, msg Missing field';

        $resp   = $this->http->request('POST', API_BASE_URL . '/rooms', [
            // 'name' bị bỏ trống
            'location' => 'Tang 1',
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_ROOM_CREATE_002 - Test ngoại lệ: Thiếu field "location"
        // Nhánh   : save() → !Input::post("location") → jsonecho lỗi
        // Input   : POST chỉ có name, thiếu location
        // Expected: result=0, msg chứa "Missing field"
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_CREATE_002';
        $desc   = 'Test ngoại lệ: Thiếu field "location" → result=0, msg Missing field';

        $resp   = $this->http->request('POST', API_BASE_URL . '/rooms', [
            'name'     => 'Phong Test',
            // 'location' bị bỏ trống
        ]);
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_ROOM_CREATE_003 - Test ngoại lệ: Trùng name + location
        // Nhánh   : save() → count($result) > 0 → jsonecho lỗi trùng
        // Input   : POST với name+location đã tồn tại trong DB
        // Expected: result=0, msg chứa "exists"
        // CheckDB : Không tạo thêm bản ghi trùng (vẫn chỉ có 1 bản ghi)
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_CREATE_003';
        $desc   = 'Test ngoại lệ: Trùng name+location → result=0, không tạo bản ghi mới';

        // Lấy phòng đầu tiên trong DB để dùng làm dữ liệu trùng
        $existingRoom = $this->db->query(
            "SELECT name, location FROM " . TABLE_ROOMS . " LIMIT 1"
        );

        if (!empty($existingRoom)) {
            $dupName     = $existingRoom[0]['name'];
            $dupLocation = $existingRoom[0]['location'];

            $countBefore = $this->db->countRooms();

            $resp   = $this->http->request('POST', API_BASE_URL . '/rooms', [
                'name'     => $dupName,
                'location' => $dupLocation,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            // CheckDB: số phòng không được tăng thêm
            $countAfter = $this->db->countRooms();
            if ($passed && $countAfter !== $countBefore) {
                $passed = false;
            }

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'. CheckDB OK: không tạo bản ghi mới"
                    : ($countAfter !== $countBefore
                        ? "CheckDB THẤT BẠI: Bản ghi trùng vẫn được tạo!"
                        : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A'))
            );
        } else {
            $this->recordResult($testId, $desc, false, 'SKIP: DB không có phòng nào để test trùng lặp');
        }


        // ---------------------------------------------------------------
        // TC_ROOM_CREATE_004 - Test chuẩn: Tạo phòng mới thành công
        // Nhánh   : save() → $Room->save() → result=1, trả data
        // Input   : POST với name+location hợp lệ, chưa tồn tại
        // Expected: result=1, data có id, name, location đúng
        // CheckDB : Bản ghi mới xuất hiện trong tn_rooms với đúng dữ liệu
        // Rollback: Xóa phòng vừa tạo trong finally block
        // ---------------------------------------------------------------
        $testId    = 'TC_ROOM_CREATE_004';
        $desc      = 'Test chuẩn: Tạo phòng hợp lệ → result=1, CheckDB, Rollback';
        $newName   = 'Phong Test TC004 ' . time();
        $newLoc    = 'Tang Test ' . time();
        $createdId = 0;

        // Đếm số phòng trước khi tạo
        $countBefore = $this->db->countRooms();

        try {
            $resp = $this->http->request('POST', API_BASE_URL . '/rooms', [
                'name'     => $newName,
                'location' => $newLoc,
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && !empty($body['data']['id'])
                   && ($body['data']['name']     ?? '') === $newName
                   && ($body['data']['location'] ?? '') === $newLoc;

            // CheckDB: Đếm sau khi tạo - phải tăng đúng 1 bản ghi
            $countAfter = $this->db->countRooms();
            $checkMsg   = '';

            if ($passed) {
                $createdId = (int)$body['data']['id'];

                if ($countAfter !== $countBefore + 1) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: countBefore={$countBefore}, countAfter={$countAfter}";
                } else {
                    // Xác minh từng field trong DB
                    $savedRoom = $this->db->getRoomById($createdId);
                    $nameOk    = ($savedRoom['name']     ?? '') === $newName;
                    $locOk     = ($savedRoom['location'] ?? '') === $newLoc;

                    if (!$nameOk || !$locOk) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: "
                            . (!$nameOk ? "name sai; " : "")
                            . (!$locOk  ? "location sai" : "");
                    } else {
                        $checkMsg = "CheckDB OK: ID={$createdId}, "
                            . "name='{$savedRoom['name']}', "
                            . "location='{$savedRoom['location']}'";
                    }
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );

        } finally {
            // Rollback: Xóa phòng vừa tạo (luôn chạy dù test pass hay fail)
            if ($createdId > 0) {
                $this->db->rollbackDeleteRoom($createdId);
                $afterRollback = $this->db->getRoomById($createdId);
                if ($afterRollback === null) {
                    echo Color::green("         ↳ Rollback OK: Phòng ID={$createdId} đã xóa khỏi DB\n");
                } else {
                    echo Color::red("         ↳ Rollback THẤT BẠI: Phòng ID={$createdId} vẫn còn trong DB\n");
                }
            }
        }
    }


    // =========================================================================
    // NHÓM TEST 3: CHI TIẾT PHÒNG
    // Kiểm tra GET /api/room/{id} → RoomController::getById()
    //
    // Các nhánh cần phủ:
    //   - Không có token        → redirect
    //   - Không phải admin      → lỗi phân quyền
    //   - Thiếu id trong route  → lỗi (nhánh !isset($Route->params->id))
    //   - ID không tồn tại      → lỗi "not available"
    //   - ID hợp lệ             → trả đúng dữ liệu
    // =========================================================================
    public function runDetailTests(): void
    {
        $this->printGroupHeader("NHÓM 3: CHI TIẾT PHÒNG (GET /api/room/{id})");


        // ---------------------------------------------------------------
        // TC_ROOM_DETAIL_001 - Test ngoại lệ: Không có token
        // Nhánh   : process() → !$AuthUser → header redirect
        // Input   : GET /api/room/1 không có token
        // Expected: HTTP 302 hoặc result=0
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_DETAIL_001';
        $desc   = 'Test ngoại lệ: Không có token → bị redirect hoặc result=0';

        $resp   = $this->unauthHttp->request('GET', API_BASE_URL . '/room/1');
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
        // TC_ROOM_DETAIL_002 - Test ngoại lệ: ID không tồn tại
        // Nhánh   : getById() → !$Room->isAvailable() → jsonecho lỗi
        // Input   : GET /api/room/999999 (ID chắc chắn không tồn tại)
        // Expected: result=0, msg chứa "not available"
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_DETAIL_002';
        $desc   = 'Test ngoại lệ: GET /api/room/999999 không tồn tại → result=0';

        $resp   = $this->http->request('GET', API_BASE_URL . '/room/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_ROOM_DETAIL_003 - Test chuẩn: Lấy phòng tồn tại
        // Nhánh   : getById() → isAvailable() → trả data
        // Input   : GET /api/room/{id} với id hợp lệ
        // Expected: result=1, data có id, name, location đúng
        // CheckDB : Dữ liệu trả về phải khớp với bản ghi trong DB
        // ---------------------------------------------------------------
        $testId   = 'TC_ROOM_DETAIL_003';
        $roomId   = $this->db->getAnyRoomId();

        if (!$roomId) {
            $this->recordResult($testId,
                'Test chuẩn: GET /api/room/{id} hợp lệ → result=1, dữ liệu đúng',
                false, 'SKIP: DB không có phòng nào'
            );
        } else {
            $desc = "Test chuẩn: GET /api/room/{$roomId} → result=1, dữ liệu đúng, CheckDB";

            $resp   = $this->http->request('GET', API_BASE_URL . '/room/' . $roomId);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 0) == 1
                   && isset($body['data']['id'])
                   && isset($body['data']['name'])
                   && isset($body['data']['location']);

            // CheckDB: Xác minh dữ liệu trả về khớp với DB
            $checkMsg = '';
            if ($passed && $this->db->connected) {
                $dbRoom   = $this->db->getRoomById($roomId);
                $idOk     = ((int)($body['data']['id']) === $roomId);
                $nameOk   = ($body['data']['name']     === $dbRoom['name']);
                $locOk    = ($body['data']['location'] === $dbRoom['location']);

                if (!$idOk || !$nameOk || !$locOk) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: Dữ liệu API không khớp DB";
                } else {
                    $checkMsg = "CheckDB OK: id={$roomId}, "
                        . "name='{$body['data']['name']}', "
                        . "location='{$body['data']['location']}'";
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
            );
        }
    }


    // =========================================================================
    // NHÓM TEST 4: CẬP NHẬT PHÒNG
    // Kiểm tra PUT /api/room/{id} → RoomController::update()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin    → lỗi phân quyền
    //   - Thiếu field name    → lỗi missing field
    //   - Thiếu field location → lỗi missing field
    //   - Trùng name+location với phòng khác → lỗi duplicate
    //   - ID không tồn tại    → lỗi not available
    //   - Dữ liệu hợp lệ      → cập nhật thành công
    // =========================================================================
    public function runUpdateTests(): void
    {
        $this->printGroupHeader("NHÓM 4: CẬP NHẬT PHÒNG (PUT /api/room/{id})");

        // Tạo 1 phòng test để cập nhật, dùng xuyên suốt nhóm test này
        $setupResp = $this->http->request('POST', API_BASE_URL . '/rooms', [
            'name'     => 'Phong Update Setup ' . time(),
            'location' => 'Tang Setup ' . time(),
        ]);
        $testRoomId       = (int)($setupResp['body']['data']['id']       ?? 0);
        $testRoomName     = $setupResp['body']['data']['name']            ?? '';
        $testRoomLocation = $setupResp['body']['data']['location']        ?? '';

        if (!$testRoomId) {
            echo Color::yellow("  [SKIP] Không tạo được phòng test cho nhóm UPDATE\n");
            return;
        }
        echo Color::yellow("  [Setup] Tạo phòng test ID={$testRoomId} để test Update\n");


        try {

            // ---------------------------------------------------------------
            // TC_ROOM_UPDATE_001 - Test ngoại lệ: Thiếu field "name"
            // Nhánh   : update() → !Input::put("name") → jsonecho lỗi
            // Input   : PUT chỉ có location, thiếu name
            // Expected: result=0, msg chứa "Missing"
            // ---------------------------------------------------------------
            $testId = 'TC_ROOM_UPDATE_001';
            $desc   = 'Test ngoại lệ: PUT thiếu field "name" → result=0, Missing field';

            $resp   = $this->http->request('PUT', API_BASE_URL . '/room/' . $testRoomId, [
                // 'name' bị bỏ trống
                'location' => 'Tang Moi',
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_ROOM_UPDATE_002 - Test ngoại lệ: Thiếu field "location"
            // Nhánh   : update() → !Input::put("location") → jsonecho lỗi
            // Input   : PUT chỉ có name, thiếu location
            // Expected: result=0, msg chứa "Missing"
            // ---------------------------------------------------------------
            $testId = 'TC_ROOM_UPDATE_002';
            $desc   = 'Test ngoại lệ: PUT thiếu field "location" → result=0, Missing field';

            $resp   = $this->http->request('PUT', API_BASE_URL . '/room/' . $testRoomId, [
                'name'     => 'Ten Moi',
                // 'location' bị bỏ trống
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_ROOM_UPDATE_003 - Test ngoại lệ: Trùng name+location với phòng khác
            // Nhánh   : update() → count($result) > 0 → jsonecho lỗi
            // Input   : PUT với name+location của phòng khác đã tồn tại
            // Expected: result=0, msg chứa "exists"
            // ---------------------------------------------------------------
            $testId      = 'TC_ROOM_UPDATE_003';
            $desc        = 'Test ngoại lệ: PUT trùng name+location với phòng khác → result=0';
            $otherRoom   = $this->db->query(
                "SELECT name, location FROM " . TABLE_ROOMS . " WHERE id != ? LIMIT 1",
                [$testRoomId]
            );

            if (!empty($otherRoom)) {
                $dupName = $otherRoom[0]['name'];
                $dupLoc  = $otherRoom[0]['location'];

                $resp   = $this->http->request('PUT', API_BASE_URL . '/room/' . $testRoomId, [
                    'name'     => $dupName,
                    'location' => $dupLoc,
                ]);
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 1) == 0;

                $this->recordResult($testId, $desc, $passed,
                    $passed
                        ? "result=0, msg='{$body['msg']}'"
                        : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
                );
            } else {
                $this->recordResult($testId, $desc, false,
                    'SKIP: DB chỉ có 1 phòng, không test được trùng lặp'
                );
            }


            // ---------------------------------------------------------------
            // TC_ROOM_UPDATE_004 - Test ngoại lệ: ID không tồn tại
            // Nhánh   : update() → !$Room->isAvailable() → jsonecho lỗi
            // Input   : PUT /api/room/999999
            // Expected: result=0, msg chứa "not available"
            // ---------------------------------------------------------------
            $testId = 'TC_ROOM_UPDATE_004';
            $desc   = 'Test ngoại lệ: PUT ID=999999 không tồn tại → result=0';

            $resp   = $this->http->request('PUT', API_BASE_URL . '/room/999999', [
                'name'     => 'Ten Khong Ton Tai',
                'location' => 'Tang Khong Ton Tai',
            ]);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );


            // ---------------------------------------------------------------
            // TC_ROOM_UPDATE_005 - Test chuẩn: Cập nhật phòng thành công
            // Nhánh   : update() → $Room->save() → result=1
            // Input   : PUT /api/room/{id} với name và location hợp lệ, chưa trùng
            // Expected: result=1, data trả về có name và location mới
            // CheckDB : Bản ghi trong tn_rooms phải được cập nhật đúng
            // Rollback: Khôi phục name+location về giá trị ban đầu
            // ---------------------------------------------------------------
            $testId     = 'TC_ROOM_UPDATE_005';
            $desc       = "Test chuẩn: PUT /api/room/{$testRoomId} hợp lệ → result=1, CheckDB, Rollback";
            $updName    = 'Phong Da Cap Nhat ' . time();
            $updLoc     = 'Tang Da Cap Nhat ' . time();

            try {
                $resp   = $this->http->request('PUT', API_BASE_URL . '/room/' . $testRoomId, [
                    'name'     => $updName,
                    'location' => $updLoc,
                ]);
                $body   = $resp['body'];
                $passed = ($body['result'] ?? 0) == 1
                       && ($body['data']['name']     ?? '') === $updName
                       && ($body['data']['location'] ?? '') === $updLoc;

                // CheckDB: Xác minh bản ghi trong DB đã thay đổi đúng
                $checkMsg = '';
                if ($this->db->connected) {
                    $dbRoom   = $this->db->getRoomById($testRoomId);
                    $nameOk   = ($dbRoom['name']     ?? '') === $updName;
                    $locOk    = ($dbRoom['location'] ?? '') === $updLoc;

                    if ($passed && (!$nameOk || !$locOk)) {
                        $passed   = false;
                        $checkMsg = "CheckDB THẤT BẠI: DB name='{$dbRoom['name']}' "
                            . "(mong đợi='{$updName}'), location='{$dbRoom['location']}' "
                            . "(mong đợi='{$updLoc}')";
                    } elseif ($passed) {
                        $checkMsg = "CheckDB OK: name='{$dbRoom['name']}', "
                            . "location='{$dbRoom['location']}' (đã cập nhật đúng)";
                    }
                }

                $this->recordResult($testId, $desc, $passed,
                    $passed ? $checkMsg : "result={$body['result']}, msg=" . ($body['msg'] ?? $checkMsg)
                );

            } finally {
                // Rollback: Khôi phục name+location về giá trị ban đầu
                $this->db->rollbackRestoreRoom($testRoomId, $testRoomName, $testRoomLocation);
                $restored = $this->db->getRoomById($testRoomId);

                if (($restored['name']     ?? '') === $testRoomName
                 && ($restored['location'] ?? '') === $testRoomLocation) {
                    echo Color::green("         ↳ Rollback OK: Phòng khôi phục về "
                        . "name='{$testRoomName}', location='{$testRoomLocation}'\n");
                } else {
                    echo Color::red("         ↳ Rollback THẤT BẠI: Kiểm tra thủ công ID={$testRoomId}\n");
                }
            }

        } finally {
            // Rollback toàn bộ nhóm: Xóa phòng test đã tạo ở setup
            $this->db->rollbackDeleteRoom($testRoomId);
            $afterCleanup = $this->db->getRoomById($testRoomId);
            if ($afterCleanup === null) {
                echo Color::green("  [Teardown] Rollback OK: Đã xóa phòng test setup ID={$testRoomId}\n");
            } else {
                echo Color::red("  [Teardown] Rollback THẤT BẠI: Phòng test ID={$testRoomId} vẫn còn\n");
            }
        }
    }


    // =========================================================================
    // NHÓM TEST 5: XÓA PHÒNG
    // Kiểm tra DELETE /api/room/{id} → RoomController::delete()
    //
    // Các nhánh cần phủ:
    //   - Không phải admin       → lỗi phân quyền
    //   - Thiếu id               → lỗi
    //   - id == 1 (default)      → không cho xóa
    //   - ID không tồn tại       → lỗi not available
    //   - Phòng còn có doctor    → không cho xóa
    //   - Phòng hợp lệ           → xóa thành công
    // =========================================================================
    public function runDeleteTests(): void
    {
        $this->printGroupHeader("NHÓM 5: XÓA PHÒNG (DELETE /api/room/{id})");


        // ---------------------------------------------------------------
        // TC_ROOM_DELETE_001 - Test ngoại lệ: Xóa phòng mặc định (ID=1)
        // Nhánh   : delete() → $Route->params->id == 1 → jsonecho lỗi
        // Input   : DELETE /api/room/1
        // Expected: result=0, msg chứa "default" hoặc "can't be deleted"
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_DELETE_001';
        $desc   = "Test ngoại lệ: DELETE /api/room/1 (phòng mặc định) → result=0, không cho xóa";

        $resp   = $this->http->request('DELETE', API_BASE_URL . '/room/1');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_ROOM_DELETE_002 - Test ngoại lệ: ID không tồn tại
        // Nhánh   : delete() → !$Room->isAvailable() → jsonecho lỗi
        // Input   : DELETE /api/room/999999
        // Expected: result=0, msg chứa "not available"
        // ---------------------------------------------------------------
        $testId = 'TC_ROOM_DELETE_002';
        $desc   = 'Test ngoại lệ: DELETE /api/room/999999 không tồn tại → result=0';

        $resp   = $this->http->request('DELETE', API_BASE_URL . '/room/999999');
        $body   = $resp['body'];
        $passed = ($body['result'] ?? 1) == 0;

        $this->recordResult($testId, $desc, $passed,
            $passed
                ? "result=0, msg='{$body['msg']}'"
                : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
        );


        // ---------------------------------------------------------------
        // TC_ROOM_DELETE_003 - Test ngoại lệ: Phòng còn có doctor
        // Nhánh   : delete() → count($result) > 0 → jsonecho lỗi
        // Input   : DELETE /api/room/{id_có_doctor}
        // Expected: result=0, msg chứa "doctors"
        // ---------------------------------------------------------------
        $testId       = 'TC_ROOM_DELETE_003';
        $desc         = 'Test ngoại lệ: Xóa phòng đang có doctor → result=0';
        $roomWithDoc  = $this->db->getRoomIdWithDoctor();

        if (!$roomWithDoc || $roomWithDoc == 1) {
            // Nếu không tìm được, skip
            $this->recordResult($testId, $desc, false,
                'SKIP: Không tìm được phòng có doctor trong DB (hoặc chỉ có room id=1)'
            );
        } else {
            $resp   = $this->http->request('DELETE', API_BASE_URL . '/room/' . $roomWithDoc);
            $body   = $resp['body'];
            $passed = ($body['result'] ?? 1) == 0;

            $this->recordResult($testId, $desc, $passed,
                $passed
                    ? "result=0, msg='{$body['msg']}'"
                    : "Mong đợi result=0, nhận result=" . ($body['result'] ?? 'N/A')
            );
        }


        // ---------------------------------------------------------------
        // TC_ROOM_DELETE_004 - Test chuẩn: Xóa phòng thành công
        // Nhánh   : delete() → $Room->delete() → result=1
        // Input   : Tạo phòng mới → DELETE /api/room/{id_vừa_tạo}
        // Expected: result=1, msg chứa "deleted"
        // CheckDB : Bản ghi không còn tồn tại trong tn_rooms
        // Rollback: Phòng đã bị xóa = trạng thái mong muốn, chỉ cần xác nhận DB sạch
        // ---------------------------------------------------------------
        $testId    = 'TC_ROOM_DELETE_004';
        $desc      = 'Test chuẩn: Tạo phòng → DELETE → CheckDB xác nhận đã xóa';
        $createdId = 0;

        try {
            // Tạo phòng test để xóa
            $createResp = $this->http->request('POST', API_BASE_URL . '/rooms', [
                'name'     => 'Phong Can Xoa ' . time(),
                'location' => 'Tang Can Xoa ' . time(),
            ]);
            $createBody = $createResp['body'];

            if (($createBody['result'] ?? 0) != 1) {
                $this->recordResult($testId, $desc, false,
                    'Không tạo được phòng test: ' . ($createBody['msg'] ?? 'N/A')
                );
                return;
            }

            $createdId = (int)($createBody['data']['id'] ?? 0);

            // Xác nhận phòng tồn tại trước khi xóa
            $existsBefore = $this->db->getRoomById($createdId);
            if (!$existsBefore) {
                $this->recordResult($testId, $desc, false,
                    "CheckDB: Phòng ID={$createdId} không tồn tại sau khi tạo - lỗi bất thường"
                );
                return;
            }

            // Gọi API xóa
            $deleteResp = $this->http->request('DELETE', API_BASE_URL . '/room/' . $createdId);
            $deleteBody = $deleteResp['body'];
            $passed     = ($deleteBody['result'] ?? 0) == 1;

            // CheckDB: Xác minh bản ghi đã biến mất khỏi tn_rooms
            $checkMsg = '';
            if ($this->db->connected) {
                $existsAfter = $this->db->getRoomById($createdId);

                if ($passed && $existsAfter !== null) {
                    $passed   = false;
                    $checkMsg = "CheckDB THẤT BẠI: Bản ghi ID={$createdId} vẫn còn trong DB sau khi xóa";
                } elseif ($passed) {
                    $checkMsg = "CheckDB OK: Bản ghi ID={$createdId} đã xóa hoàn toàn khỏi tn_rooms";
                    $createdId = 0; // Đã xóa thành công → không cần rollback
                }
            }

            $this->recordResult($testId, $desc, $passed,
                $passed ? $checkMsg : "result={$deleteBody['result']}, msg="
                    . ($deleteBody['msg'] ?? $checkMsg)
            );

        } finally {
            // Rollback: Chỉ cần nếu API xóa thất bại nhưng bản ghi vẫn còn
            if ($createdId > 0) {
                $this->db->rollbackDeleteRoom($createdId);
                echo Color::yellow("         ↳ Rollback: Xóa thủ công phòng ID={$createdId} "
                    . "(API xóa thất bại)\n");
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
            . "║   TEST MODULE PHÒNG KHÁM - UMBRELLA CORPORATION     ║\n"
            . "║   PHP cURL Test Script - Kết quả trên Terminal      ║\n"
            . "╚══════════════════════════════════════════════════════╝"
        )) . "\n";
        echo Color::yellow("  API URL   : " . API_BASE_URL)         . "\n";
        echo Color::yellow("  Thời gian : " . date('Y-m-d H:i:s'))  . "\n";
        echo Color::yellow("  Database  : " . DB_NAME . "@" . DB_HOST) . "\n";

        // ==============================================================
        // BƯỚC BẮT BUỘC: Đăng nhập trước khi test Room / Rooms
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
            case 'all':
            default:
                $this->runListTests();
                $this->runCreateTests();
                $this->runDetailTests();
                $this->runUpdateTests();
                $this->runDeleteTests();
                break;
        }

        $this->printSummary();
        $this->db->close();
    }
}


// =============================================================================
// ĐIỂM VÀO CHƯƠNG TRÌNH
// Cách chạy: php RoomTest.php [list|create|detail|update|delete|all]
// =============================================================================
$group  = $argv[1] ?? 'all';
$runner = new TestRunner();
$runner->run($group);