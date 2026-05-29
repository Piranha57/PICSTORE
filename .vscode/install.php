<?php
/**
 * PICSTORE - Complete Monolithic System Setup & Self-Extracting Script
 * Creates directories, initializes schemas, generates frontend/backend files, and deploys assets.
 * UPDATED: Fixed gallery comments & added Trending Images to Insights.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$setup_completed = false;
$setup_message = "";
$setup_error = false;

// Pre-flight checks
$diagnostics = [
    'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'write_permissions' => is_writable(__DIR__),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'pdo_mysql' => extension_loaded('pdo_mysql')
];

// Configuration defaults
$db_type = 'sqlite'; // 'sqlite' or 'mysql'
$mysql_host = 'localhost';
$mysql_user = 'root';
$mysql_pass = '';
$mysql_name = 'picstore_db';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_install'])) {
    $db_type = $_POST['db_type'] ?? 'sqlite';
    $mysql_host = $_POST['mysql_host'] ?? 'localhost';
    $mysql_user = $_POST['mysql_user'] ?? 'root';
    $mysql_pass = $_POST['mysql_pass'] ?? '';
    $mysql_name = $_POST['mysql_name'] ?? 'picstore_db';

    try {
        if (!$diagnostics['write_permissions']) {
            throw new Exception("The web server does not have write permissions to this folder. Please configure your directory permissions.");
        }

        $directories = [
            'config',
            'auth',
            'blog',
            'image',
            'admin',
            'api',
            'js',
            'images/uploaded-images'
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new Exception("Failed to create directory branch: $dir. Please verify write access.");
                }
            }
        }

        $db_config_content = "<?php\n// Database PDO Configuration Wrapper\n";
        if ($db_type === 'mysql') {
            $db_config_content .= "define('DB_TYPE', 'mysql');\ndefine('DB_HOST', '" . addslashes($mysql_host) . "');\ndefine('DB_USER', '" . addslashes($mysql_user) . "');\ndefine('DB_PASS', '" . addslashes($mysql_pass) . "');\ndefine('DB_NAME', '" . addslashes($mysql_name) . "');\n";
            $db_config_content .= "
try {
    \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException \$e) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . \$e->getMessage()]));
}
";
        } else {
            $db_config_content .= "define('DB_TYPE', 'sqlite');\ndefine('DB_PATH', __DIR__ . '/picstore.db');\n";
            $db_config_content .= "
try {
    \$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    \$pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException \$e) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . \$e->getMessage()]));
}
";
        }
        file_put_contents('config/database.php', $db_config_content);

        // Include Database directly to create schemas
        require_once 'config/database.php';

        $queries = [];
        if ($db_type === 'mysql') {
            // Create database if not exists
            $init_pdo = new PDO("mysql:host=$mysql_host", $mysql_user, $mysql_pass);
            $init_pdo->exec("CREATE DATABASE IF NOT EXISTS `$mysql_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `$mysql_name`;");

            $queries[] = "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) DEFAULT 'Content Creator',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;";

            $queries[] = "CREATE TABLE IF NOT EXISTS images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                title VARCHAR(100) NOT NULL,
                tags VARCHAR(255),
                is_private TINYINT DEFAULT 0,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;";

            $queries[] = "CREATE TABLE IF NOT EXISTS blogs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                tags VARCHAR(255) NULL,
                is_private TINYINT DEFAULT 0,
                featured_image_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (featured_image_id) REFERENCES images(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;";

            $queries[] = "CREATE TABLE IF NOT EXISTS comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                blog_id INT NULL,
                image_id INT NULL,
                user_id INT NOT NULL,
                comment_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;";

            $queries[] = "CREATE TABLE IF NOT EXISTS blog_likes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                blog_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_blog_user_like (blog_id, user_id)
            ) ENGINE=InnoDB;";

            $queries[] = "CREATE TABLE IF NOT EXISTS image_likes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                image_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_image_user_like (image_id, user_id)
            ) ENGINE=InnoDB;";
        } else {
            // SQLite Tables
            $queries[] = "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'Content Creator',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );";

            $queries[] = "CREATE TABLE IF NOT EXISTS images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                title TEXT NOT NULL,
                tags TEXT,
                is_private INTEGER DEFAULT 0,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );";

            $queries[] = "CREATE TABLE IF NOT EXISTS blogs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                tags TEXT NULL,
                is_private INTEGER DEFAULT 0,
                featured_image_id INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (featured_image_id) REFERENCES images(id) ON DELETE SET NULL
            );";

            $queries[] = "CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                blog_id INTEGER NULL,
                image_id INTEGER NULL,
                user_id INTEGER NOT NULL,
                comment_text TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );";

            $queries[] = "CREATE TABLE IF NOT EXISTS blog_likes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                blog_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(blog_id, user_id)
            );";

            $queries[] = "CREATE TABLE IF NOT EXISTS image_likes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(image_id, user_id)
            );";
        }

        foreach ($queries as $q) {
            $pdo->exec($q);
        }

        // --- AUTOMATED SCHEMA REPAIR FOR OLD DATABASES ---
        if ($db_type === 'mysql') {
            $pdo->exec("ALTER TABLE comments MODIFY blog_id INT NULL;");
            $pdo->exec("ALTER TABLE comments MODIFY image_id INT NULL;");
        } else {
            $stmt = $pdo->query("PRAGMA table_info(comments)");
            $colsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $needsRecreate = false;
            foreach ($colsInfo as $col) {
                if (($col['name'] === 'blog_id' || $col['name'] === 'image_id') && $col['notnull'] == 1) {
                    $needsRecreate = true;
                }
            }
            if ($needsRecreate) {
                // Safely drop and recreate the table to wipe the old constraints
                $pdo->exec("DROP TABLE comments;");
                $pdo->exec("CREATE TABLE comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    blog_id INTEGER NULL,
                    image_id INTEGER NULL,
                    user_id INTEGER NOT NULL,
                    comment_text TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (blog_id) REFERENCES blogs(id) ON DELETE CASCADE,
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );");
            }
        }
        // --- END SCHEMA REPAIR ---

        if ($db_type === 'mysql') {
            // Check images table structure
            $stmt = $pdo->query("SHOW COLUMNS FROM images LIKE 'is_private'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE images ADD COLUMN is_private TINYINT DEFAULT 0;");
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM blogs LIKE 'is_private'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE blogs ADD COLUMN is_private TINYINT DEFAULT 0;");
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM blogs LIKE 'tags'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE blogs ADD COLUMN tags VARCHAR(255) NULL;");
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'image_id'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE comments ADD COLUMN image_id INT NULL;");
            }
        } else {
            $stmt = $pdo->query("PRAGMA table_info(images)");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('is_private', $cols)) {
                $pdo->exec("ALTER TABLE images ADD COLUMN is_private INTEGER DEFAULT 0;");
            }
            $stmt = $pdo->query("PRAGMA table_info(blogs)");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('is_private', $cols)) {
                $pdo->exec("ALTER TABLE blogs ADD COLUMN is_private INTEGER DEFAULT 0;");
            }
            if (!in_array('tags', $cols)) {
                $pdo->exec("ALTER TABLE blogs ADD COLUMN tags TEXT NULL;");
            }
            $stmt = $pdo->query("PRAGMA table_info(comments)");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('image_id', $cols)) {
                $pdo->exec("ALTER TABLE comments ADD COLUMN image_id INTEGER NULL;");
            }
        }

        // --- AUTH: REGISTER ---
        file_put_contents('auth/register.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'All registration parameters are required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Username or email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $role = 'Content Creator'; // Default role
    
    if (strtolower($username) === 'admin') {
        $role = 'Administrator';
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $email, $hash, $role]);
    
    $userId = $pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['member_since'] = date('F Y');

    echo json_encode([
        'status' => 'success',
        'message' => 'User registered successfully!',
        'user' => [
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'member_since' => $_SESSION['member_since']
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- AUTH: LOGIN ---
        file_put_contents('auth/login.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'status' => 'success',
            'logged_in' => true,
            'user' => [
                'username' => $_SESSION['username'],
                'email' => $_SESSION['email'],
                'role' => $_SESSION['role'],
                'member_since' => $_SESSION['member_since'] ?? date('F Y')
            ]
        ]);
    } else {
        echo json_encode(['status' => 'success', 'logged_in' => false]);
    }
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Credentials must not be empty.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['member_since'] = date('F Y', strtotime($user['created_at']));

        echo json_encode([
            'status' => 'success',
            'message' => 'Authenticated successfully!',
            'user' => [
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'member_since' => $_SESSION['member_since']
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password credentials.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database execution failed: ' . $e->getMessage()]);
}
CODE
        );

        // --- AUTH: LOGOUT ---
        file_put_contents('auth/logout.php', <<<'CODE'
<?php
session_start();
$_SESSION = [];
session_destroy();
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'Logged out successfully!']);
exit;
CODE
        );

        // --- BLOG: CREATE BLOG ---
        file_put_contents('blog/create_blog.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. You must be authenticated to post content.']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$is_private = intval($_POST['is_private'] ?? 0);
$user_id = $_SESSION['user_id'];
$image_id = null;

try {
    if (isset($_FILES['blog_image']) && $_FILES['blog_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['blog_image']['tmp_name'];
        $file_name = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($_FILES['blog_image']['name']));
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $unique_name = md5(uniqid(rand(), true)) . '.' . $ext;
            $upload_dir = dirname(__DIR__) . '/images/uploaded-images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $target_path = $upload_dir . $unique_name;
            
            if (move_uploaded_file($file_tmp, $target_path)) {
                $db_relative_path = 'images/uploaded-images/' . $unique_name;
                
                $stmtImg = $pdo->prepare("INSERT INTO images (user_id, file_path, title, tags, is_private) VALUES (?, ?, ?, ?, ?)");
                $stmtImg->execute([$user_id, $db_relative_path, $title . " Image", $tags, $is_private]);
                $image_id = $pdo->lastInsertId();
            }
        }
    }

    $stmt = $pdo->prepare("INSERT INTO blogs (user_id, title, content, tags, is_private, featured_image_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $title, $content, $tags, $is_private, $image_id]);
    
    echo json_encode(['status' => 'success', 'message' => 'Blog published successfully!', 'blog_id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
}
CODE
        );

        // --- BLOG: VIEW BLOG / RETRIEVE ALL ---
        file_put_contents('blog/view_blog.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

$blog_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$current_user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? 'guest';

try {
    if ($blog_id > 0) {
        $stmt = $pdo->prepare("
            SELECT b.*, u.username as author, i.file_path as image_path,
                   (SELECT COUNT(*) FROM blog_likes WHERE blog_id = b.id) as likes_count,
                   (SELECT COUNT(*) FROM blog_likes WHERE blog_id = b.id AND user_id = ?) as user_liked
            FROM blogs b 
            JOIN users u ON b.user_id = u.id 
            LEFT JOIN images i ON b.featured_image_id = i.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$current_user_id, $blog_id]);
        $blog = $stmt->fetch();

        if (!$blog) {
            echo json_encode(['status' => 'error', 'message' => 'Article not found.']);
            exit;
        }

        if ($blog['is_private'] == 1 && $blog['user_id'] !== $current_user_id && $role !== 'Administrator') {
            echo json_encode(['status' => 'error', 'message' => 'This is a private article. Access denied.']);
            exit;
        }

        $commentStmt = $pdo->prepare("
            SELECT c.*, u.username as commentator 
            FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.blog_id = ? 
            ORDER BY c.created_at DESC
        ");
        $commentStmt->execute([$blog_id]);
        $comments = $commentStmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'blog' => $blog,
            'comments' => $comments,
            'current_user_id' => $current_user_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT b.*, u.username as author, i.file_path as image_path,
                   (SELECT COUNT(*) FROM blog_likes WHERE blog_id = b.id) as likes_count,
                   (SELECT COUNT(*) FROM blog_likes WHERE blog_id = b.id AND user_id = ?) as user_liked
            FROM blogs b 
            JOIN users u ON b.user_id = u.id 
            LEFT JOIN images i ON b.featured_image_id = i.id 
            WHERE b.is_private = 0 OR b.user_id = ? OR ? = 'Administrator'
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$current_user_id, $current_user_id, $role]);
        $blogs = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'blogs' => $blogs]);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
}
CODE
        );

        // --- BLOG/IMAGE: WRITE COMMENT (UPDATED FIX) ---
        file_put_contents('blog/comment.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login to comment.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// Explicit null parsing for robust database acceptance (fixes gallery comment bug)
$blog_id = isset($data['blog_id']) && $data['blog_id'] !== null && $data['blog_id'] !== '' ? intval($data['blog_id']) : null;
$image_id = isset($data['image_id']) && $data['image_id'] !== null && $data['image_id'] !== '' ? intval($data['image_id']) : null;
$comment_text = trim($data['comment_text'] ?? '');
$user_id = $_SESSION['user_id'];

if (empty($comment_text)) {
    echo json_encode(['status' => 'error', 'message' => 'Comment text must not be empty.']);
    exit;
}

if ($blog_id === null && $image_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'A valid comment target (blog or image) must be supplied.']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO comments (blog_id, image_id, user_id, comment_text) VALUES (?, ?, ?, ?)");
    $stmt->execute([$blog_id, $image_id, $user_id, $comment_text]);
    
    echo json_encode(['status' => 'success', 'message' => 'Comment added successfully!']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- BLOG/IMAGE: TOGGLE LIKE API ---
        file_put_contents('blog/toggle_like.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Authentication required to toggle likes.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$target_id = intval($data['target_id'] ?? 0);
$type = $data['type'] ?? 'blog'; 
$user_id = $_SESSION['user_id'];

if ($target_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid target payload ID.']);
    exit;
}

$table = ($type === 'image') ? 'image_likes' : 'blog_likes';
$col = ($type === 'image') ? 'image_id' : 'blog_id';

try {
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE $col = ? AND user_id = ?");
    $stmt->execute([$target_id, $user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $del = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $del->execute([$existing['id']]);
        $status = 'unliked';
    } else {
        $ins = $pdo->prepare("INSERT INTO $table ($col, user_id) VALUES (?, ?)");
        $ins->execute([$target_id, $user_id]);
        $status = 'liked';
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
    $cnt->execute([$target_id]);
    $new_count = $cnt->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'like_status' => $status,
        'new_count' => $new_count
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database operation failed: ' . $e->getMessage()]);
}
CODE
        );

        // --- IMAGE: UPLOAD TO GALLERY ---
        file_put_contents('image/upload.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$is_private = intval($_POST['is_private'] ?? 0);
$user_id = $_SESSION['user_id'];

if (!isset($_FILES['gallery_file']) || $_FILES['gallery_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Valid picture asset file is required.']);
    exit;
}

$file_tmp = $_FILES['gallery_file']['tmp_name'];
$file_name = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($_FILES['gallery_file']['name']));
$ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid image format. Allowed: JPG, PNG, GIF, WEBP.']);
    exit;
}

try {
    $unique_name = md5(uniqid(rand(), true)) . '.' . $ext;
    $upload_dir = dirname(__DIR__) . '/images/uploaded-images/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $target_path = $upload_dir . $unique_name;

    if (move_uploaded_file($file_tmp, $target_path)) {
        $db_relative_path = 'images/uploaded-images/' . $unique_name;

        $stmt = $pdo->prepare("INSERT INTO images (user_id, file_path, title, tags, is_private) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $db_relative_path, $title, $tags, $is_private]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Image added to gallery!',
            'image' => [
                'title' => $title,
                'file_path' => $db_relative_path,
                'tags' => $tags,
                'uploaded_at' => date('M d, Y')
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file. Check directory write rights.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- IMAGE: RETRIEVE GALLERY ---
        file_put_contents('image/gallery.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? 'guest';

$image_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    if ($image_id > 0) {
        $stmt = $pdo->prepare("
            SELECT i.*, u.username as owner,
                   (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id) as likes_count,
                   (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id AND user_id = ?) as user_liked
            FROM images i 
            JOIN users u ON i.user_id = u.id 
            WHERE i.id = ?
        ");
        $stmt->execute([$current_user_id, $image_id]);
        $image = $stmt->fetch();

        if (!$image) {
            echo json_encode(['status' => 'error', 'message' => 'Graphic asset not found.']);
            exit;
        }

        if ($image['is_private'] == 1 && $image['user_id'] !== $current_user_id && $role !== 'Administrator') {
            echo json_encode(['status' => 'error', 'message' => 'Private media. Access Denied.']);
            exit;
        }

        $commentStmt = $pdo->prepare("
            SELECT c.*, u.username as commentator 
            FROM comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.image_id = ? 
            ORDER BY c.created_at DESC
        ");
        $commentStmt->execute([$image_id]);
        $comments = $commentStmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'image' => $image,
            'comments' => $comments
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT i.*, u.username as owner,
                   (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id) as likes_count,
                   (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id AND user_id = ?) as user_liked
            FROM images i 
            JOIN users u ON i.user_id = u.id 
            WHERE i.is_private = 0 OR i.user_id = ? OR ? = 'Administrator'
            ORDER BY i.uploaded_at DESC
        ");
        $stmt->execute([$current_user_id, $current_user_id, $role]);
        $images = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'images' => $images]);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- API: SEARCH ENGINE ---
        file_put_contents('api/search.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$current_user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? 'guest';

if (empty($query)) {
    echo json_encode(['status' => 'success', 'blogs' => [], 'images' => [], 'users' => []]);
    exit;
}

try {
    $term = '%' . $query . '%';

    $blogStmt = $pdo->prepare("
        SELECT b.*, u.username as author, i.file_path as image_path,
               (SELECT COUNT(*) FROM blog_likes WHERE blog_id = b.id) as likes_count
        FROM blogs b 
        JOIN users u ON b.user_id = u.id 
        LEFT JOIN images i ON b.featured_image_id = i.id 
        WHERE (b.title LIKE ? OR b.content LIKE ? OR b.tags LIKE ?) 
          AND (b.is_private = 0 OR b.user_id = ? OR ? = 'Administrator')
        ORDER BY b.created_at DESC
    ");
    $blogStmt->execute([$term, $term, $term, $current_user_id, $role]);
    $blogs = $blogStmt->fetchAll();

    $imgStmt = $pdo->prepare("
        SELECT i.*, u.username as owner,
               (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id) as likes_count
        FROM images i 
        JOIN users u ON i.user_id = u.id 
        WHERE (i.title LIKE ? OR i.tags LIKE ?) 
          AND (i.is_private = 0 OR i.user_id = ? OR ? = 'Administrator')
        ORDER BY i.uploaded_at DESC
    ");
    $imgStmt->execute([$term, $term, $current_user_id, $role]);
    $images = $imgStmt->fetchAll();

    $userStmt = $pdo->prepare("
        SELECT username, role, created_at FROM users 
        WHERE username LIKE ? 
        ORDER BY username ASC
    ");
    $userStmt->execute([$term]);
    $users = $userStmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'query' => $query,
        'blogs' => $blogs,
        'images' => $images,
        'users' => $users
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Search error: ' . $e->getMessage()]);
}
CODE
        );

        // --- BLOGS DELETION API ---
        file_put_contents('blog/delete_blog.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    $stmt = $pdo->prepare("SELECT user_id FROM blogs WHERE id = ?");
    $stmt->execute([$id]);
    $blog = $stmt->fetch();

    if ($blog) {
        if ($blog['user_id'] == $_SESSION['user_id'] || $_SESSION['role'] === 'Administrator') {
            $del = $pdo->prepare("DELETE FROM blogs WHERE id = ?");
            $del->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Blog deleted successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'You do not own this blog.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Blog not found.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- IMAGE DELETION API ---
        file_put_contents('image/delete_image.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
    $stmt = $pdo->prepare("SELECT user_id, file_path FROM images WHERE id = ?");
    $stmt->execute([$id]);
    $image = $stmt->fetch();

    if ($image) {
        if ($image['user_id'] == $_SESSION['user_id'] || $_SESSION['role'] === 'Administrator') {
            // Delete actual file on disk
            $full_path = dirname(__DIR__) . '/' . $image['file_path'];
            if (file_exists($full_path) && is_file($full_path)) {
                unlink($full_path);
            }

            $del = $pdo->prepare("DELETE FROM images WHERE id = ?");
            $del->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Image deleted successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'You do not own this image.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Image not found.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
CODE
        );

        // --- PROFILE HISTORY PULL API ---
        file_put_contents('auth/profile_data.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $blogStmt = $pdo->prepare("SELECT id, title, created_at, content, is_private FROM blogs WHERE user_id = ? ORDER BY created_at DESC");
    $blogStmt->execute([$user_id]);
    $blogs = $blogStmt->fetchAll();

    $imgStmt = $pdo->prepare("SELECT id, title, file_path, uploaded_at, tags, is_private FROM images WHERE user_id = ? ORDER BY uploaded_at DESC");
    $imgStmt->execute([$user_id]);
    $images = $imgStmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'blogs' => $blogs,
        'images' => $images
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Profile load error: ' . $e->getMessage()]);
}
CODE
        );

        // --- ADMIN PORTAL PANEL CONTROLLER ---
        file_put_contents('admin/dashboard.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Administrator privileges required.']);
    exit;
}

try {
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $blogCount = $pdo->query("SELECT COUNT(*) FROM blogs")->fetchColumn();
    $imageCount = $pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
    $commentCount = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();

    $users = $pdo->query("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
    $blogs = $pdo->query("SELECT b.id, b.title, u.username as author, b.created_at FROM blogs b JOIN users u ON b.user_id = u.id ORDER BY b.created_at DESC")->fetchAll();
    $images = $pdo->query("SELECT i.id, i.title, i.file_path, u.username as owner, i.uploaded_at FROM images i JOIN users u ON i.user_id = u.id ORDER BY i.uploaded_at DESC")->fetchAll();

    echo json_encode([
        'status' => 'success',
        'metrics' => [
            'total_users' => $userCount,
            'total_blogs' => $blogCount,
            'total_images' => $imageCount,
            'total_comments' => $commentCount
        ],
        'users' => $users,
        'blogs' => $blogs,
        'images' => $images
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
CODE
        );

        // --- ADMIN USER MODIFICATION ACTION ---
        file_put_contents('admin/manage_users.php', <<<'CODE'
<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$target_user_id = intval($data['user_id'] ?? 0);
$action = $data['action'] ?? ''; 

if ($target_user_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user ID.']);
    exit;
}

try {
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully.']);
    } elseif ($action === 'promote') {
        $stmt = $pdo->prepare("UPDATE users SET role = 'Administrator' WHERE id = ?");
        $stmt->execute([$target_user_id]);
        echo json_encode(['status' => 'success', 'message' => 'User promoted to Administrator!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Unknown action request.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Transaction failed: ' . $e->getMessage()]);
}
CODE
        );

        // --- TRENDS PROCESSOR API (UPDATED WITH IMAGES) ---
        file_put_contents('api/trending.php', <<<'CODE'
<?php
header('Content-Type: application/json');
require_once '../config/database.php';

try {
    // Process public tags from both blogs and images
    $stmt1 = $pdo->query("SELECT tags FROM blogs WHERE is_private = 0");
    $stmt2 = $pdo->query("SELECT tags FROM images WHERE is_private = 0");

    $tagCounts = [];
    while ($row = $stmt1->fetch()) {
        if (!empty($row['tags'])) {
            $parts = explode(',', $row['tags']);
            foreach ($parts as $t) {
                $clean = trim(strtolower($t));
                if ($clean) {
                    $tagCounts[$clean] = ($tagCounts[$clean] ?? 0) + 1;
                }
            }
        }
    }
    while ($row = $stmt2->fetch()) {
        if (!empty($row['tags'])) {
            $parts = explode(',', $row['tags']);
            foreach ($parts as $t) {
                $clean = trim(strtolower($t));
                if ($clean) {
                    $tagCounts[$clean] = ($tagCounts[$clean] ?? 0) + 1;
                }
            }
        }
    }

    arsort($tagCounts);
    $topTagsRaw = array_slice($tagCounts, 0, 3, true);
    
    $formattedTags = [];
    foreach ($topTagsRaw as $tag => $count) {
        $formattedTags[] = ['tag' => $tag, 'count' => $count];
    }

    // Top active creators based on database contribution density
    $stmtAuthors = $pdo->query("
        SELECT u.username, u.role,
               (SELECT COUNT(*) FROM blogs WHERE user_id = u.id) +
               (SELECT COUNT(*) FROM images WHERE user_id = u.id) as contributions
        FROM users u
        ORDER BY contributions DESC
        LIMIT 3
    ");
    $topAuthors = $stmtAuthors->fetchAll();

    // Fetch top 4 most liked public images for the trending gallery view
    $stmtTrendingImages = $pdo->query("
        SELECT i.*, u.username as owner,
               (SELECT COUNT(*) FROM image_likes WHERE image_id = i.id) as likes_count
        FROM images i
        JOIN users u ON i.user_id = u.id
        WHERE i.is_private = 0
        ORDER BY likes_count DESC, i.uploaded_at DESC
        LIMIT 4
    ");
    $trendingImages = $stmtTrendingImages->fetchAll();

    echo json_encode([
        'status' => 'success',
        'tags' => $formattedTags,
        'authors' => $topAuthors,
        'images' => $trendingImages
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error aggregating dynamic trends: ' . $e->getMessage()]);
}
CODE
        );

        
        // --- INDEX.HTML ---
        file_put_contents('index.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Home Feed</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div id="toastNotification" class="fixed top-5 right-5 z-50 transform translate-y-[-100px] opacity-0 transition-all duration-300 bg-emerald-600 text-white font-bold py-4 px-6 rounded-2xl shadow-2xl flex items-center gap-3">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastText">System message</span>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a id="navAdminBtn" href="admin-panel.html" class="hidden flex items-center gap-3.5 px-4 py-3 rounded-xl text-yellow-300 font-bold hover:bg-white/10 hover:translate-x-1 transition-all duration-200 border border-yellow-500/30">
          <span class="w-8 h-8 rounded-lg bg-yellow-500/10 flex items-center justify-center text-sm"><i class="fa-solid fa-crown"></i></span>
          <span>Admin Console</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
        <a id="registerMenuBtn" href="register.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user-plus"></i></span>
          <span>Register</span>
        </a>
      </nav>
      <div class="mt-auto pt-6 border-t border-white/10 text-white/60 text-xs text-center flex flex-col gap-1.5">
         <p class="font-bold text-white/80">Local Server: Live</p>
         <p>Monolithic Architecture v2.5</p>
      </div>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <header class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 mb-8">
        <form class="flex w-full max-w-2xl gap-2" onsubmit="searchContent('homeSearch'); return false;">
          <div class="relative flex-1">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input id="homeSearch" type="search" placeholder="Search blogs, images, or users..." class="w-full pl-11 pr-4 py-3.5 rounded-2xl border border-brand-border bg-white shadow-inner focus:outline-none focus:ring-2 focus:ring-brand-accent/50 text-sm">
          </div>
          <button type="submit" class="bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white px-6 py-3.5 rounded-2xl font-semibold shadow-lg transition-all text-sm">Search</button>
        </form>
      </header>

      <section class="p-8 md:p-12 rounded-[32px] border border-brand-border bg-white shadow-xl mb-8">
        <h1 class="text-3xl md:text-5xl font-black text-brand-buttonBlueAlt tracking-tight mb-4">Welcome to PICSTORE</h1>
        <p class="text-gray-500 max-w-2xl leading-relaxed text-sm md:text-base">Manage your blogs and images in the cloud with ease. Create posts, organize your gallery, and publish your stories from a beautiful dashboard.</p>
        <a class="inline-block bg-gradient-to-r from-[#26d4c5] to-[#1bd0c1] text-[#0f325f] px-8 py-4 rounded-2xl font-bold shadow-lg transform hover:-translate-y-0.5 transition-all text-sm mt-6" href="register.html">Get Started Now</a>
      </section>

      <!-- Category Filter Section -->
      <div class="mb-8 p-6 bg-white border border-brand-border rounded-3xl shadow-sm">
        <h3 class="text-sm font-black text-brand-buttonBlueAlt uppercase tracking-wider mb-4 flex items-center gap-2">
           <i class="fa-solid fa-tags text-teal-500"></i> Category / Tag Filters
        </h3>
        <div id="categoryFilters" class="flex flex-wrap gap-2">
           <button class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-accent text-[#0f325f] shadow" onclick="filterFeed('All')">All Feed</button>
        </div>
      </div>

      <h2 class="text-2xl font-extrabold text-brand-buttonBlue mb-6 flex items-center justify-between gap-2">
         <span class="flex items-center gap-2"><span class="w-1.5 h-6 bg-brand-accent rounded-full"></span>Unified Home Feed</span>
         <span id="feedCount" class="text-xs text-gray-400 font-bold tracking-wide bg-gray-100 px-3 py-1 rounded-full">Showing 0 items</span>
      </h2>
      <section id="blogsFeedGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
         <div class="col-span-full text-center p-12 text-gray-400">Loading unified feed assets...</div>
      </section>

      <!-- Unified Detail Popup Overlay (for viewing image comments and details) -->
      <div id="mediaDetailOverlay" class="hidden fixed inset-0 bg-brand-top/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[32px] w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden border border-brand-border shadow-2xl relative">
          <button onclick="closeMediaDetail()" class="absolute top-6 right-6 w-11 h-11 rounded-full bg-brand-top/10 hover:bg-brand-top/20 flex items-center justify-center text-brand-buttonBlue text-lg transition-all z-10">
            <i class="fa-solid fa-xmark"></i>
          </button>
          <div class="overflow-y-auto p-8 md:p-12 space-y-8 flex-1" id="mediaOverlayContent">
             <!-- Populated dynamically -->
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/search.js"></script>
</body>
</html>
CODE
        );

        // --- REGISTER.HTML ---
        file_put_contents('register.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Register</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span>Sign In</span>
        </a>
        <a href="register.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user-plus"></i></span>
          <span>Register</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md justify-center">
      <section class="max-w-md mx-auto w-full bg-white rounded-3xl p-8 md:p-12 border border-brand-border shadow-xl">
        <h1 class="text-2xl font-black text-brand-buttonBlueAlt mb-2">Create Account</h1>
        <p class="text-gray-400 text-xs mb-8">Register your credentials onto the relational user schemas.</p>
        <form id="registerForm" onsubmit="return validateRegister()" class="space-y-4">
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Username</label>
            <input id="registerUsername" type="text" placeholder="Username" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Email</label>
            <input id="registerEmail" type="email" placeholder="Email" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Password</label>
            <input id="registerPassword" type="password" placeholder="Password" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Confirm Password</label>
            <input id="registerConfirm" type="password" placeholder="Confirm Password" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <button class="w-full bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white py-3.5 rounded-xl font-bold transition-all text-sm mt-4" type="submit">Register</button>
          <p class="text-center text-xs text-gray-400 mt-4">Already have an account? <a class="text-teal-600 font-bold hover:underline" href="login.html">Login</a></p>
        </form>
      </section>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/validation.js"></script>
</body>
</html>
CODE
        );

        // --- LOGIN.HTML ---
        file_put_contents('login.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md justify-center">
      <section class="max-w-md mx-auto w-full bg-white rounded-3xl p-8 md:p-12 border border-brand-border shadow-xl">
        <h1 class="text-2xl font-black text-brand-buttonBlueAlt mb-2">Access Portal</h1>
        <p class="text-gray-400 text-xs mb-8">Deploy session authentication models for write access.</p>
        <form id="loginForm" onsubmit="return validateLogin()" class="space-y-5">
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Username</label>
            <input id="loginUsername" type="text" placeholder="Username" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-xs mb-2">Password</label>
            <input id="loginPassword" type="password" placeholder="Password" required class="w-full px-4.5 py-3 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <button class="w-full bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white py-3.5 rounded-xl font-bold transition-all text-sm mt-4" type="submit">Login</button>
          <p class="text-center text-xs text-gray-400 mt-4">Don't have an account? <a class="text-teal-600 font-bold hover:underline" href="register.html">Register</a></p>
        </form>
      </section>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/validation.js"></script>
</body>
</html>
CODE
        );

        // --- CREATE-BLOG.HTML ---
        file_put_contents('create-blog.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Create Blog</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div id="toastNotification" class="fixed top-5 right-5 z-50 transform translate-y-[-100px] opacity-0 transition-all duration-300 bg-emerald-600 text-white font-bold py-4 px-6 rounded-2xl shadow-2xl flex items-center gap-3">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastText">System message</span>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <section class="max-w-3xl mx-auto w-full bg-white rounded-3xl p-8 md:p-12 border border-brand-border shadow-xl">
        <h1 class="text-3xl font-black text-brand-buttonBlueAlt mb-2">Publish New Article</h1>
        <p class="text-gray-400 text-sm mb-8">Share insights, tag keywords, and choose featured cover graphics.</p>

        <div id="authNotice" class="hidden mb-6 p-4 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 font-semibold text-sm"></div>
        <form id="blogForm" onsubmit="return validateBlog()" class="space-y-6">
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-sm mb-2.5">Blog Title:</label>
            <input id="blogTitle" type="text" placeholder="Enter your blog title" required class="w-full px-4.5 py-3.5 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-sm mb-2.5">Visibility Mode:</label>
            <select id="isPrivate" class="w-full px-4.5 py-3.5 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm font-semibold">
               <option value="0">🌍 Public (Visible to everyone on home feed)</option>
               <option value="1">🔒 Private (Only visible to you and administrators)</option>
            </select>
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-sm mb-2.5">Tags</label>
            <input id="tags" name="tags" type="text" placeholder="e.g. sunset, food, nature" required class="w-full px-4.5 py-3.5 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm">
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-sm mb-2.5">Content</label>
            <textarea id="blogContent" rows="10" placeholder="Start writing your blog..." required class="w-full px-4.5 py-3.5 rounded-xl border border-brand-border bg-[#f7fafd] focus:outline-none text-sm resize-y"></textarea>
          </div>
          <div>
            <label class="block text-brand-buttonBlueAlt font-bold text-sm mb-2.5">Featured Image</label>
            <input id="blogImage" type="file" accept="image/*" onchange="previewImage(event)" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
            <div id="imagePreview" class="border border-dashed border-gray-300 rounded-xl h-24 flex items-center justify-center text-xs text-gray-400 bg-gray-50 overflow-hidden mt-3">No image selected</div>
            
            <!-- AI Tag Generator Button -->
            <button type="button" id="aiBlogTagBtn" onclick="generateAITags('blogImage', 'tags', 'aiBlogTagBtn')" class="mt-3 inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-xs font-bold rounded-xl shadow-md transition-all hidden">
              <i class="fa-solid fa-wand-magic-sparkles"></i> ✨ Generate AI Tags
            </button>
          </div>
          <button class="w-full bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white py-4 rounded-xl font-bold tracking-wider transition-all" type="submit">Publish</button>
        </form>
      </section>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/upload.js"></script>
  <script src="js/validation.js"></script>
</body>
</html>
CODE
        );

        // --- GALLERY.HTML ---
        file_put_contents('gallery.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Gallery</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div id="toastNotification" class="fixed top-5 right-5 z-50 transform translate-y-[-100px] opacity-0 transition-all duration-300 bg-emerald-600 text-white font-bold py-4 px-6 rounded-2xl shadow-2xl flex items-center gap-3">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastText">System message</span>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <header class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 mb-8">
        <form class="flex w-full max-w-2xl gap-2" onsubmit="searchContent('gallerySearch'); return false;">
          <div class="relative flex-1">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input id="gallerySearch" type="search" placeholder="Search gallery images..." class="w-full pl-11 pr-4 py-3.5 rounded-2xl border border-brand-border bg-white shadow-inner focus:outline-none focus:ring-2 focus:ring-brand-accent/50 text-sm">
          </div>
          <button type="submit" class="bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white px-6 py-3.5 rounded-2xl font-semibold shadow-lg transition-all text-sm">Search</button>
        </form>
      </header>

      <section class="gallery-shell flex flex-col gap-8">
        <div class="gallery-header">
          <div>
            <h1 class="text-3xl font-black text-brand-buttonBlueAlt tracking-tight">Gallery</h1>
            <p class="text-gray-400 text-sm">Upload, manage, and preview your images for use inside blogs.</p>
          </div>
        </div>

        <div class="bg-white border border-brand-border rounded-3xl p-6 shadow-md max-w-3xl">
          <h2 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-cloud-arrow-up mr-2 text-teal-500"></i>Add New Image</h2>

          <div id="authNotice" class="hidden mb-6 p-4 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 font-semibold text-sm"></div>
          <form id="galleryAddForm" class="grid grid-cols-1 md:grid-cols-2 gap-4" onsubmit="return addGalleryImage(event)">
            <div class="space-y-3">
              <input id="galleryImageTitle" type="text" placeholder="Enter a title for the image" required class="w-full px-4 py-3 rounded-lg border border-brand-border bg-gray-50 focus:outline-none text-xs">
              <input id="galleryImageTags" name="tags" type="text" placeholder="e.g. sunset, food, nature" required class="w-full px-4 py-3 rounded-lg border border-brand-border bg-gray-50 focus:outline-none text-xs">
              <select id="galleryIsPrivate" class="w-full px-4 py-3 rounded-lg border border-brand-border bg-gray-50 focus:outline-none text-xs font-semibold text-[#0f2d4e]">
                 <option value="0">🌍 Public (Visible to community)</option>
                 <option value="1">🔒 Private (Only you and admins can view)</option>
              </select>
              <input id="galleryUpload" type="file" accept="image/*" onchange="previewGalleryImage(event)" required class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
            </div>
            <div class="flex flex-col justify-between items-stretch gap-3">
              <div id="galleryAddPreview" class="border border-dashed border-gray-300 rounded-xl h-24 flex items-center justify-center text-xs text-gray-400 bg-gray-50 overflow-hidden">No image selected</div>
              
              <!-- AI Tag Generator Button -->
              <button type="button" id="aiGalleryTagBtn" onclick="generateAITags('galleryUpload', 'galleryImageTags', 'aiGalleryTagBtn')" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-bold py-2.5 rounded-xl text-xs transition-all flex items-center justify-center gap-1.5 hidden shadow">
                 <i class="fa-solid fa-wand-magic-sparkles"></i> ✨ Auto-Generate AI Tags
              </button>
              
              <button class="bg-brand-buttonBlue text-white font-bold py-3 rounded-xl hover:bg-brand-buttonBlueAlt transition-all text-xs" type="submit">Add Image</button>
            </div>
          </form>
        </div>

        <div id="galleryGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-6">
          <div class="col-span-full text-center py-12 text-gray-400">
            No images uploaded yet.
          </div>
        </div>
      </section>

      <!-- Unified Detail Popup Overlay (for viewing image comments and details) -->
      <div id="mediaDetailOverlay" class="hidden fixed inset-0 bg-brand-top/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[32px] w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden border border-brand-border shadow-2xl relative">
          <button onclick="closeMediaDetail()" class="absolute top-6 right-6 w-11 h-11 rounded-full bg-brand-top/10 hover:bg-brand-top/20 flex items-center justify-center text-brand-buttonBlue text-lg transition-all z-10">
            <i class="fa-solid fa-xmark"></i>
          </button>
          <div class="overflow-y-auto p-8 md:p-12 space-y-8 flex-1" id="mediaOverlayContent">
             <!-- Populated dynamically -->
          </div>
        </div>
      </div>
    </main>
  </div>

  <script src="js/app.js"></script>
  <script src="js/upload.js"></script>
  <script src="js/search.js"></script>
</body>
</html>
CODE
        );

        // --- PROFILE.HTML ---
        file_put_contents('profile.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Profile</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div id="toastNotification" class="fixed top-5 right-5 z-50 transform translate-y-[-100px] opacity-0 transition-all duration-300 bg-emerald-600 text-white font-bold py-4 px-6 rounded-2xl shadow-2xl flex items-center gap-3">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastText">System message</span>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <section class="p-8 md:p-12 rounded-[32px] border border-brand-border bg-white shadow-xl mb-8">
        <h1 class="text-3xl md:text-5xl font-black text-brand-buttonBlueAlt tracking-tight mb-2">Your Profile</h1>
        <p class="text-gray-500 max-w-2xl leading-relaxed text-sm md:text-base">View and update your account details, manage your preferences, and keep your CMS profile up to date.</p>
      </section>

      <section class="grid grid-cols-1 xl:grid-cols-3 gap-8">
        <article class="bg-white border border-brand-border rounded-3xl p-8 shadow-lg flex flex-col h-fit">
          <h2 class="text-lg font-bold text-brand-buttonBlueAlt mb-6">Account Information</h2>
          <div class="flex items-center gap-4.5 mb-6">
            <div id="profileAvatar" class="w-16 h-16 rounded-2xl bg-gradient-to-br from-brand-top to-brand-bottom flex items-center justify-center text-white font-extrabold text-2xl uppercase">US</div>
            <div>
              <p class="font-extrabold text-[#0f2d4e] text-lg"><span id="profileUsername">johndoe</span></p>
              <p class="text-xs text-brand-accent font-bold"><span id="profileRole">Content Creator</span></p>
            </div>
          </div>
          <div class="space-y-4 border-t border-brand-border pt-6 text-sm">
             <p class="flex justify-between"><strong class="text-gray-400 font-medium">Email:</strong> <span class="text-brand-buttonBlueAlt" id="profileEmail">johndoe@example.com</span></p>
             <p class="flex justify-between"><strong class="text-gray-400 font-medium">Member since:</strong> <span class="text-brand-buttonBlueAlt" id="profileMemberSince">January 2025</span></p>
          </div>
          <div class="profile-actions flex flex-col gap-3 mt-8 pt-6 border-t border-brand-border">
            <button class="bg-brand-buttonBlue text-white font-bold py-3 rounded-xl hover:bg-brand-buttonBlueAlt transition-all text-sm" onclick="logoutUser()">Logout Session</button>
          </div>
        </article>

        <article class="bg-white border border-brand-border rounded-3xl p-8 shadow-lg xl:col-span-2">
          <h2 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-feather mr-2 text-teal-500"></i>Recent Blogs</h2>
          <div id="blogHistory" class="space-y-4">
            <div class="empty-state text-center py-6 text-gray-400">
              <p>No blog posts yet.</p>
            </div>
          </div>
        </article>

        <article class="col-span-full bg-white border border-brand-border rounded-3xl p-8 shadow-lg">
          <h2 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-image mr-2 text-teal-500"></i>Uploaded Images</h2>
          <div id="imageHistory" class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="empty-state col-span-full text-center py-6 text-gray-400">
              <p>No images uploaded yet.</p>
            </div>
          </div>
        </article>
      </section>

      <!-- Unified Detail Popup Overlay (for viewing image comments and details) -->
      <div id="mediaDetailOverlay" class="hidden fixed inset-0 bg-brand-top/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[32px] w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden border border-brand-border shadow-2xl relative">
          <button onclick="closeMediaDetail()" class="absolute top-6 right-6 w-11 h-11 rounded-full bg-brand-top/10 hover:bg-brand-top/20 flex items-center justify-center text-brand-buttonBlue text-lg transition-all z-10">
            <i class="fa-solid fa-xmark"></i>
          </button>
          <div class="overflow-y-auto p-8 md:p-12 space-y-8 flex-1" id="mediaOverlayContent">
             <!-- Populated dynamically -->
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/search.js"></script>
</body>
</html>
CODE
        );

        // --- SEARCH.HTML ---
        file_put_contents('search.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Search</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link class="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <header class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 mb-8">
        <form class="search-box flex w-full max-w-2xl gap-2" onsubmit="searchContent('mainSearch'); return false;">
          <input id="mainSearch" type="search" placeholder="Type key phrases and hit Enter..." class="w-full px-5 py-3.5 rounded-2xl border border-brand-border bg-white shadow-inner focus:outline-none text-sm">
          <button class="bg-brand-buttonBlue hover:bg-brand-buttonBlueAlt text-white px-6 rounded-2xl font-semibold" type="submit">Search</button>
        </form>
      </header>
      <section class="gallery-shell" style="max-width:100%;">
         <div id="searchResults" class="bg-white border border-brand-border rounded-3xl p-8 shadow-md">
            <p class="text-gray-400">Type key phrases and hit search to pull information from databases...</p>
         </div>
      </section>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script src="js/search.js"></script>
</body>
</html>
CODE
        );

        // --- TRENDING.HTML (UPDATED WITH GALLERY GRID) ---
        file_put_contents('trending.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Trending</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a href="login.html" id="authMenuBtn" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-lock"></i></span>
          <span id="authMenuBtnText">Sign In</span>
        </a>
      </nav>
    </aside>
    
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <section class="p-8 md:p-12 rounded-[32px] border border-brand-border bg-white shadow-xl mb-8">
        <h1 class="text-3xl md:text-5xl font-black text-brand-buttonBlueAlt tracking-tight mb-4">Trending Now</h1>
        <p class="text-gray-500 max-w-2xl leading-relaxed text-sm md:text-base">Stay up to date with the most popular dynamic visual tags, top contributing creators, and highly-rated images across PICSTORE.</p>
      </section>
      
      <section class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
        <!-- Dyn Tag Card -->
        <article class="bg-white border border-brand-border rounded-3xl p-8 shadow-md">
          <h2 class="text-xl font-bold text-brand-buttonBlueAlt mb-6 flex items-center gap-2">
            <span class="w-1 h-5 bg-brand-accent rounded-full"></span>Top 3 Dynamic Tags
          </h2>
          <ul class="space-y-4" id="trendingTagsContainer">
             <li class="p-4 text-center text-gray-400 font-medium">Analyzing database tags...</li>
          </ul>
        </article>
        
        <!-- Dyn Contributors Card -->
        <article class="bg-white border border-brand-border rounded-3xl p-8 shadow-md">
          <h2 class="text-xl font-bold text-brand-buttonBlueAlt mb-4 flex items-center gap-2">
            <span class="w-1 h-5 bg-brand-accent rounded-full"></span>Top Contributors
          </h2>
          <p class="text-gray-400 text-sm mb-6">Creators driving the highest CMS publication volumes.</p>
          <ul class="space-y-4" id="trendingAuthorsContainer">
             <li class="p-4 text-center text-gray-400 font-medium">Computing active creators...</li>
          </ul>
        </article>
      </section>

      <section class="bg-white border border-brand-border rounded-3xl p-8 shadow-md">
         <h2 class="text-xl font-bold text-brand-buttonBlueAlt mb-6 flex items-center gap-2">
            <span class="w-1 h-5 bg-brand-accent rounded-full"></span>Trending Gallery Images
         </h2>
         <div id="trendingImagesContainer" class="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div class="col-span-full text-center text-gray-400 font-medium p-6">Loading trending graphics...</div>
         </div>
      </section>
    </main>
  </div>
  
  <script src="js/app.js"></script>
  <script>
     document.addEventListener('DOMContentLoaded', () => {
        fetch('api/trending.php')
          .then(r => r.json())
          .then(data => {
             if (data.status === 'success') {
                // Populate Tags
                const tagsContainer = document.getElementById('trendingTagsContainer');
                tagsContainer.innerHTML = data.tags.length 
                  ? data.tags.map(t => `
                     <li class="flex items-center justify-between p-4 bg-teal-50/50 border border-brand-border rounded-xl">
                        <strong class="text-sm font-bold text-brand-buttonBlue">#${escapeHtml(t.tag)}</strong>
                        <span class="text-xs bg-brand-buttonBlue text-white font-bold py-1 px-3 rounded-full">${t.count} items</span>
                     </li>
                  `).join('')
                  : '<li class="p-4 text-center text-gray-400">No public tags configured.</li>';

                // Populate Authors
                const authorsContainer = document.getElementById('trendingAuthorsContainer');
                authorsContainer.innerHTML = data.authors.length 
                  ? data.authors.map(a => `
                     <li class="flex items-center gap-4 p-4 bg-teal-50/50 border border-brand-border rounded-xl">
                        <div class="w-10 h-10 rounded-lg bg-teal-500 flex items-center justify-center text-white font-extrabold text-sm uppercase">${a.username.slice(0, 2)}</div>
                        <div class="flex-1">
                           <strong class="text-sm font-bold text-brand-buttonBlue">@${escapeHtml(a.username)}</strong>
                           <p class="text-xs text-gray-400">${a.role}</p>
                        </div>
                        <span class="text-xs font-bold text-brand-accentStrong">${a.contributions} publications</span>
                     </li>
                  `).join('')
                  : '<li class="p-4 text-center text-gray-400">No contributions resolved.</li>';

                // Populate Images
                const imagesContainer = document.getElementById('trendingImagesContainer');
                imagesContainer.innerHTML = data.images && data.images.length
                  ? data.images.map(img => `
                      <div class="group relative rounded-2xl overflow-hidden border border-brand-border bg-gray-50 shadow-sm hover:shadow-md transition-all cursor-pointer" onclick="window.location.href='gallery.html?id=${img.id}'">
                          <div class="h-40 overflow-hidden flex items-center justify-center bg-gray-100">
                              <img src="${img.file_path}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="${escapeHtml(img.title)}">
                          </div>
                          <div class="absolute top-3 right-3 bg-white/95 backdrop-blur-sm text-rose-600 font-bold text-xs px-3 py-1.5 rounded-full shadow flex items-center gap-1.5">
                              <i class="fa-solid fa-heart"></i> ${img.likes_count}
                          </div>
                          <div class="p-4">
                              <strong class="block text-brand-buttonBlue text-sm truncate mb-1">${escapeHtml(img.title)}</strong>
                              <p class="text-[10px] text-gray-400 font-semibold uppercase mb-2">@${escapeHtml(img.owner)}</p>
                              ${img.tags ? `<p class="text-teal-600 font-bold text-[10px] truncate">#${escapeHtml(img.tags.replace(/,/g, ' #'))}</p>` : ''}
                          </div>
                      </div>
                  `).join('')
                  : '<div class="col-span-full text-center text-gray-400 py-6">No trending images available yet.</div>';

             } else {
                const errMsg = `<li class="p-4 text-rose-600 bg-rose-50 border border-rose-200 rounded-xl font-medium">⚠️ Error: ${escapeHtml(data.message)}</li>`;
                document.getElementById('trendingTagsContainer').innerHTML = errMsg;
                document.getElementById('trendingAuthorsContainer').innerHTML = errMsg;
                document.getElementById('trendingImagesContainer').innerHTML = `<p class="col-span-full p-4 text-rose-600 font-medium">⚠️ Error loading images.</p>`;
             }
          })
          .catch(() => {
             const errMsg = `<li class="p-4 text-rose-600 bg-rose-50 border border-rose-200 rounded-xl font-medium">⚠️ Connection Refused: Ensure local databases are running.</li>`;
             document.getElementById('trendingTagsContainer').innerHTML = errMsg;
             document.getElementById('trendingAuthorsContainer').innerHTML = errMsg;
             document.getElementById('trendingImagesContainer').innerHTML = `<p class="col-span-full p-4 text-rose-600 font-medium">⚠️ Connection refused.</p>`;
          });
     });
  </script>
</body>
</html>
CODE
        );

        // --- VIEW-BLOG.HTML ---
        file_put_contents('view-blog.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Read Blog</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="create-blog.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white font-semibold bg-white/10 border border-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-pen-to-square"></i></span>
          <span>Create Blog</span>
        </a>
        <a href="gallery.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-images"></i></span>
          <span>Gallery Space</span>
        </a>
        <a href="trending.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-star"></i></span>
          <span>Trending Insights</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 font-semibold hover:bg-white/10 hover:translate-x-1 transition-all duration-200">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <section class="max-w-4xl mx-auto w-full bg-white rounded-[32px] p-8 md:p-12 border border-brand-border shadow-xl">
         <div id="blogContainer">
            <p class="text-gray-400">Loading article and database files...</p>
         </div>
         <hr class="my-10 border-brand-border" />
         <h3 class="text-xl font-extrabold text-brand-buttonBlueAlt mb-6 flex items-center gap-2">
            <span class="w-1 h-5 bg-brand-accent rounded-full"></span>Article Discussion Board
         </h3>
         <div id="commentNotice" class="mb-4"></div>
         <form id="commentForm" style="display:none;" onsubmit="return submitComment(event)" class="space-y-4 mb-8">
            <label class="block">
               <span class="block text-sm font-bold text-brand-buttonBlueAlt mb-2">Add a public comment:</span>
               <textarea id="commentText" rows="3" required placeholder="Write a respectful reply..." class="w-full px-4 py-3 border border-brand-border rounded-xl bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-brand-accent/50"></textarea>
            </label>
            <button class="bg-brand-buttonBlue text-white font-semibold text-xs px-5 py-3 rounded-lg hover:bg-brand-buttonBlueAlt transition-all shadow" type="submit">Post Comment Reply</button>
         </form>
         <div id="commentsFeed" class="space-y-4">
            <p class="text-gray-400">Loading responses...</p>
         </div>
      </section>
    </main>
  </div>
  <script src="js/app.js"></script>
  <script>
     const params = new URLSearchParams(window.location.search);
     const blogId = parseInt(params.get('id') || 0);

     document.addEventListener('DOMContentLoaded', () => {
        if (blogId <= 0) {
           document.getElementById('blogContainer').innerHTML = "<p class='text-rose-600'>Invalid blog ID requested.</p>";
           return;
        }
        loadBlogArticle();
     });

     function loadBlogArticle() {
        fetch(`blog/view_blog.php?id=${blogId}`)
          .then(r => r.json())
          .then(data => {
             if (data.status === 'success') {
                const b = data.blog;
                document.title = `PICSTORE | ${escapeHtml(b.title)}`;

                document.getElementById('blogContainer').innerHTML = `
                   <h1 class="text-2xl md:text-4xl font-black text-brand-buttonBlueAlt tracking-tight mb-2">${escapeHtml(b.title)}</h1>
                   <div class="text-sm text-teal-500 font-semibold block uppercase mb-6">
                      By <strong class="text-brand-buttonBlueAlt">@${escapeHtml(b.author)}</strong> • Published on ${b.created_at}
                   </div>
                   ${b.image_path ? `
                      <div class="w-full max-h-[450px] overflow-hidden rounded-2xl border border-brand-border bg-gray-50 flex items-center justify-center mb-8">
                         <img src="${b.image_path}" class="w-full h-full object-cover" />
                      </div>
                   ` : ''}
                   <div class="text-base text-[#0f2d4e] leading-relaxed whitespace-pre-wrap">${escapeHtml(b.content)}</div>
                `;

                if (sessionStorage.getItem('picstoreLoggedIn') === 'true') {
                   document.getElementById('commentForm').style.display = 'block';
                   document.getElementById('commentNotice').innerHTML = '';
                } else {
                   document.getElementById('commentForm').style.display = 'none';
                   document.getElementById('commentNotice').innerHTML = '<p class="text-rose-600 font-semibold text-sm">Please <a href="login.html" class="underline text-brand-buttonBlue">Login</a> to participate in the conversation thread.</p>';
                }

                const feed = document.getElementById('commentsFeed');
                feed.innerHTML = data.comments.length
                   ? data.comments.map(c => `
                      <div class="bg-teal-50/20 border border-brand-border p-4 rounded-xl text-sm flex flex-col gap-1">
                         <div class="flex items-center justify-between mb-1 text-xs">
                            <strong class="text-brand-buttonBlueAlt">@${escapeHtml(c.commentator)}</strong>
                            <span class="text-gray-400 font-medium">${c.created_at}</span>
                         </div>
                         <p class="text-[#0f2d4e]">${escapeHtml(c.comment_text)}</p>
                      </div>
                   `).join('')
                   : '<p class="text-xs text-gray-400 italic">Be the first to share your thoughts on this post!</p>';
             } else {
                document.getElementById('blogContainer').innerHTML = `<p class="text-rose-600">${data.message}</p>`;
             }
          });
     }

     function submitComment(e) {
        e.preventDefault();
        const comment_text = document.getElementById('commentText').value.trim();

        fetch('blog/comment.php', {
           method: 'POST',
           headers: { 'Content-Type': 'application/json' },
           body: JSON.stringify({ blog_id: blogId, comment_text })
        })
        .then(r => r.json())
        .then(data => {
           if (data.status === 'success') {
              document.getElementById('commentText').value = '';
              loadBlogArticle();
           } else {
              alert(data.message);
           }
        });
        return false;
     }
  </script>
</body>
</html>
CODE
        );

        // --- ADMIN-PANEL.HTML ---
        file_put_contents('admin-panel.html', <<<'CODE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Admin Console</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          colors: {
            brand: {
              top: '#0e315f',
              mid: '#153d72',
              bottom: '#1d5a8a',
              accent: '#26d4c5',
              buttonBlue: '#153861',
              buttonBlueAlt: '#0d2c4f',
              border: 'rgba(30,45,68,0.12)'
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: radial-gradient(circle at top, rgba(38, 212, 197, 0.15) 0%, transparent 40%), 
                  linear-gradient(180deg, #0b2b55 0%, #1c5986 28%, #47b6cc 55%, #e7f7fb 100%);
      min-height: 100vh;
    }
  </style>
</head>
<body class="text-[#0f2d4e] overflow-x-hidden font-sans">
  <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] min-h-screen">
    <aside class="flex flex-col p-8 gap-6 lg:min-h-screen border-r border-white/10" style="background: linear-gradient(180deg, #0e315f 0%, #153d72 55%, #1d5a8a 100%); color: white;">
      <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 grid place-items-center rounded-2xl bg-white/10 border border-white/10 text-xl font-bold text-brand-accent">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <div>
          <span class="text-2xl font-extrabold tracking-wide uppercase">PICSTORE</span>
          <p class="text-[10px] text-teal-300 font-bold tracking-widest uppercase">Cloud CMS</p>
        </div>
      </div>
      <nav class="flex flex-col gap-2 mt-6">
        <a href="index.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-house"></i></span>
          <span>Home Feed</span>
        </a>
        <a href="profile.html" class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-white/95 hover:bg-white/10">
          <span class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-sm"><i class="fa-solid fa-user"></i></span>
          <span>My Profile</span>
        </a>
        <a id="navAdminBtn" href="admin-panel.html" class="flex items-center gap-3.5 px-4.5 py-3 rounded-xl text-yellow-300 font-bold hover:bg-white/10 border border-yellow-500/30">
          <span class="w-8 h-8 rounded-lg bg-yellow-500/10 flex items-center justify-center text-sm"><i class="fa-solid fa-crown"></i></span>
          <span>Admin Console</span>
        </a>
      </nav>
    </aside>
    <main class="p-6 md:p-10 flex flex-col min-h-screen bg-white/90 backdrop-blur-md">
      <section class="bg-gradient-to-r from-brand-top to-brand-bottom rounded-[28px] p-8 text-white shadow-xl mb-8">
        <h1 class="text-3xl font-black mb-2">PICSTORE Administration Panel</h1>
        <p class="text-white/80 text-sm">Platform usage diagnostics, user controls, and active system database records.</p>
      </section>

      <!-- Analytics Cards -->
      <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
         <div class="bg-white border border-brand-border rounded-2xl p-6 shadow-md text-center">
            <span class="text-xs text-gray-400 font-bold uppercase tracking-wider">Total Accounts</span>
            <h2 id="metricUsers" class="text-4xl font-extrabold text-brand-buttonBlue mt-2">0</h2>
         </div>
         <div class="bg-white border border-brand-border rounded-2xl p-6 shadow-md text-center">
            <span class="text-xs text-gray-400 font-bold uppercase tracking-wider">Total Blogs</span>
            <h2 id="metricBlogs" class="text-4xl font-extrabold text-brand-buttonBlue mt-2">0</h2>
         </div>
         <div class="bg-white border border-brand-border rounded-2xl p-6 shadow-md text-center">
            <span class="text-xs text-gray-400 font-bold uppercase tracking-wider">Gallery Uploads</span>
            <h2 id="metricImages" class="text-4xl font-extrabold text-brand-buttonBlue mt-2">0</h2>
         </div>
      </section>

      <!-- Multi-Moderate Sections -->
      <div class="space-y-8">
         <!-- Section 1: Users -->
         <section class="bg-white border border-brand-border rounded-2xl p-6 shadow-md">
            <h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-users-gear mr-2 text-teal-500"></i>Manage User Accounts</h3>
            <div class="overflow-x-auto">
               <table class="w-full text-left border-collapse">
                  <thead>
                     <tr class="bg-teal-50/50 border-b border-brand-border text-xs font-bold text-brand-buttonBlue">
                        <th class="p-4">ID</th>
                        <th class="p-4">Username</th>
                        <th class="p-4">Email</th>
                        <th class="p-4">Role</th>
                        <th class="p-4">Actions</th>
                     </tr>
                  </thead>
                  <tbody id="usersAdminTable" class="text-xs text-brand-buttonBlueAlt">
                     <tr><td colspan="5" class="p-4">Loading account records...</td></tr>
                  </tbody>
               </table>
            </div>
         </section>

         <!-- Section 2: Blogs -->
         <section class="bg-white border border-brand-border rounded-2xl p-6 shadow-md">
            <h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-file-pen mr-2 text-teal-500"></i>Moderate Blog Posts</h3>
            <div class="overflow-x-auto">
               <table class="w-full text-left border-collapse">
                  <thead>
                     <tr class="bg-teal-50/50 border-b border-brand-border text-xs font-bold text-brand-buttonBlue">
                        <th class="p-4">ID</th>
                        <th class="p-4">Title</th>
                        <th class="p-4">Author</th>
                        <th class="p-4">Actions</th>
                     </tr>
                  </thead>
                  <tbody id="blogsAdminTable" class="text-xs text-brand-buttonBlueAlt">
                     <tr><td colspan="4" class="p-4">Loading articles...</td></tr>
                  </tbody>
               </table>
            </div>
         </section>

         <!-- Section 3: Images -->
         <section class="bg-white border border-brand-border rounded-2xl p-6 shadow-md">
            <h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-images mr-2 text-teal-500"></i>Moderate Gallery Images</h3>
            <div class="overflow-x-auto">
               <table class="w-full text-left border-collapse">
                  <thead>
                     <tr class="bg-teal-50/50 border-b border-brand-border text-xs font-bold text-brand-buttonBlue">
                        <th class="p-4">ID</th>
                        <th class="p-4">Preview</th>
                        <th class="p-4">Title</th>
                        <th class="p-4">Owner</th>
                        <th class="p-4">Actions</th>
                     </tr>
                  </thead>
                  <tbody id="imagesAdminTable" class="text-xs text-brand-buttonBlueAlt">
                     <tr><td colspan="5" class="p-4">Loading graphics...</td></tr>
                  </tbody>
               </table>
            </div>
         </section>
      </div>
    </main>
  </div>

  <script src="js/app.js"></script>
  <script>
     document.addEventListener('DOMContentLoaded', () => {
        if (sessionStorage.getItem('picstoreRole') !== 'Administrator') {
           alert("Unauthorized Access. Administrative privileges required.");
           window.location.href = 'profile.html';
           return;
        }
        loadAdminConsole();
     });

     function loadAdminConsole() {
        fetch('admin/dashboard.php')
          .then(r => r.json())
          .then(data => {
             if (data.status === 'success') {
                document.getElementById('metricUsers').textContent = data.metrics.total_users;
                document.getElementById('metricBlogs').textContent = data.metrics.total_blogs;
                document.getElementById('metricImages').textContent = data.metrics.total_images;

                // Render Users Table
                const tbodyUsers = document.getElementById('usersAdminTable');
                tbodyUsers.innerHTML = data.users.map(u => `
                   <tr class="border-b border-brand-border hover:bg-gray-50/50">
                      <td class="p-4 font-bold">${u.id}</td>
                      <td class="p-4 font-semibold">${escapeHtml(u.username)}</td>
                      <td class="p-4">${escapeHtml(u.email)}</td>
                      <td class="p-4"><span class="px-2.5 py-1 rounded-full font-bold text-[10px] bg-teal-100 text-teal-800">${u.role}</span></td>
                      <td class="p-4 flex gap-2">
                         ${u.role !== 'Administrator' ? `
                            <button class="bg-amber-500 hover:bg-amber-600 text-white font-bold py-1.5 px-3 rounded-lg" onclick="adminAction(${u.id}, 'promote')">Promote</button>
                            <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold py-1.5 px-3 rounded-lg" onclick="adminAction(${u.id}, 'delete')">Delete</button>
                         ` : '<span class="text-teal-600 font-bold text-xs">Master Admin</span>'}
                      </td>
                   </tr>
                `).join('');

                // Render Blogs Table
                const tbodyBlogs = document.getElementById('blogsAdminTable');
                tbodyBlogs.innerHTML = data.blogs.length 
                  ? data.blogs.map(b => `
                     <tr class="border-b border-brand-border hover:bg-gray-50/50">
                        <td class="p-4 font-bold">${b.id}</td>
                        <td class="p-4 font-semibold">${escapeHtml(b.title)}</td>
                        <td class="p-4">@${escapeHtml(b.author)}</td>
                        <td class="p-4">
                           <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold py-1.5 px-3 rounded-lg" onclick="deleteAdminBlog(${b.id})">Delete</button>
                        </td>
                     </tr>
                  `).join('')
                  : '<tr><td colspan="4" class="p-4 text-center text-gray-400 italic">No blog posts found.</td></tr>';

                // Render Images Table
                const tbodyImages = document.getElementById('imagesAdminTable');
                tbodyImages.innerHTML = data.images.length 
                  ? data.images.map(i => `
                     <tr class="border-b border-brand-border hover:bg-gray-50/50">
                        <td class="p-4 font-bold">${i.id}</td>
                        <td class="p-4"><img src="${i.file_path}" class="w-10 h-10 object-cover rounded-lg border border-brand-border" /></td>
                        <td class="p-4 font-semibold">${escapeHtml(i.title)}</td>
                        <td class="p-4">@${escapeHtml(i.owner)}</td>
                        <td class="p-4">
                           <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold py-1.5 px-3 rounded-lg" onclick="deleteAdminImage(${i.id})">Delete</button>
                        </td>
                     </tr>
                  `).join('')
                  : '<tr><td colspan="5" class="p-4 text-center text-gray-400 italic">No gallery images found.</td></tr>';

             } else {
                alert(data.message);
                window.location.href = 'profile.html';
             }
          });
     }

     function adminAction(userId, action) {
        if (confirm(`Are you sure you want to ${action} this account record?`)) {
           fetch('admin/manage_users.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ user_id: userId, action: action })
           })
           .then(r => r.json())
           .then(data => {
              alert(data.message);
              loadAdminConsole();
           });
        }
     }

     function deleteAdminBlog(blogId) {
        if (confirm("Are you sure you want to delete this blog post?")) {
           fetch(`blog/delete_blog.php?id=${blogId}`)
             .then(r => r.json())
             .then(data => {
                alert(data.message);
                loadAdminConsole();
             });
        }
     }

     function deleteAdminImage(imageId) {
        if (confirm("Are you sure you want to delete this image?")) {
           fetch(`image/delete_image.php?id=${imageId}`)
             .then(r => r.json())
             .then(data => {
                alert(data.message);
                loadAdminConsole();
             });
        }
     }
  </script>
</body>
</html>
CODE
        );

        // --- APP.JS (UPDATED WITH GALLERY COMMENT FIX) ---
        file_put_contents('js/app.js', <<<'CODE'
let allFeedItems = []; 
let activeTagFilter = 'All';

document.addEventListener('DOMContentLoaded', () => {
  verifySessionAndLoad();
});

function verifySessionAndLoad() {
  fetch('auth/login.php')
    .then(r => r.json())
    .then(data => {
       if (data.status === 'success' && data.logged_in) {
         sessionStorage.setItem('picstoreLoggedIn', 'true');
         sessionStorage.setItem('picstoreUsername', data.user.username);
         sessionStorage.setItem('picstoreEmail', data.user.email);
         sessionStorage.setItem('picstoreRole', data.user.role);
         sessionStorage.setItem('picstoreMemberSince', data.user.member_since);
       } else {
         sessionStorage.clear();
       }
       updateAuthUI();
       loadProfileData();
       loadHomeFeed();
    })
    .catch(() => {
       updateAuthUI();
       loadProfileData();
    });
}

function showToast(message) {
  const toast = document.getElementById('toastNotification');
  const toastText = document.getElementById('toastText');
  if(!toast || !toastText) return;

  toastText.textContent = message;
  toast.style.transform = 'translateY(0)';
  toast.style.opacity = '1';

  setTimeout(() => {
     toast.style.transform = 'translateY(-100px)';
     toast.style.opacity = '0';
  }, 3000);
}

function loadProfileData() {
  if (!window.location.pathname.endsWith('profile.html')) return;
  if (!isUserLoggedIn()) {
    window.location.href = 'login.html';
    return;
  }

  const username = sessionStorage.getItem('picstoreUsername') || 'User';
  const email = sessionStorage.getItem('picstoreEmail') || 'user@example.com';
  const memberSince = sessionStorage.getItem('picstoreMemberSince') || 'January 2025';
  const role = sessionStorage.getItem('picstoreRole') || 'Content Creator';
  const avatarText = username.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase();

  document.getElementById('profileUsername').textContent = username;
  document.getElementById('profileEmail').textContent = email;
  document.getElementById('profileMemberSince').textContent = memberSince;
  document.getElementById('profileRole').textContent = role;
  document.getElementById('profileAvatar').textContent = avatarText;

  fetch('auth/profile_data.php')
    .then(r => r.json())
    .then(data => {
       if (data.status === 'success') {
          const blogHistory = document.getElementById('blogHistory');
          const imageHistory = document.getElementById('imageHistory');

          if (blogHistory) {
             blogHistory.innerHTML = data.blogs.length
               ? data.blogs.map(b => `<div class="bg-gray-50 border border-brand-border rounded-xl p-5 text-sm flex items-center justify-between gap-4">
                     <div>
                        <strong class="text-brand-buttonBlueAlt block text-base">${escapeHtml(b.title)} ${b.is_private == 1 ? '<span class="text-xs text-rose-500 font-bold ml-2">🔒 Private</span>' : '<span class="text-xs text-teal-500 font-bold ml-2">🌍 Public</span>'}</strong>
                        <span class="text-xs text-gray-400 font-medium block mt-1">Published: ${b.created_at}</span>
                     </div>
                     <div class="flex items-center gap-2">
                        <a href="view-blog.html?id=${b.id}" class="bg-brand-buttonBlue text-white text-xs font-bold py-2 px-4 rounded-lg hover:bg-brand-buttonBlueAlt">Read</a>
                        <button class="bg-rose-600 text-white text-xs font-bold py-2 px-4 rounded-lg hover:bg-rose-700" onclick="deleteBlog(${b.id})">Delete</button>
                     </div>
                  </div>`).join('')
               : '<div class="empty-state text-center py-6 text-gray-400"><p>No blog posts written yet.</p></div>';
          }

          if (imageHistory) {
             imageHistory.innerHTML = data.images.length
               ? data.images.map(i => `<div class="bg-white border border-brand-border rounded-2xl overflow-hidden shadow-sm flex flex-col justify-between cursor-pointer" onclick="openMediaDetail(${i.id})">
                     <div class="h-28 overflow-hidden bg-gray-50 flex items-center justify-center"><img src="${i.file_path}" class="w-full h-full object-cover" /></div>
                     <div class="p-3">
                       <strong class="text-xs text-brand-buttonBlueAlt">${escapeHtml(i.title)}</strong>
                       ${i.is_private == 1 ? '<span class="block text-[10px] text-rose-500 font-bold mt-1">🔒 Private</span>' : '<span class="block text-[10px] text-teal-500 font-bold mt-1">🌍 Public</span>'}
                     </div>
                  </div>`).join('')
               : '<div class="empty-state col-span-full text-center py-6 text-gray-400"><p>No images uploaded yet.</p></div>';
          }
       }
    });
}

function deleteBlog(id) {
   if (confirm("Are you sure you want to delete this blog post?")) {
      fetch(`blog/delete_blog.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
           alert(data.message);
           loadProfileData();
        });
   }
}

function loadHomeFeed() {
  const blogsGrid = document.getElementById('blogsFeedGrid');
  if (!blogsGrid) return;

  Promise.all([
     fetch('blog/view_blog.php').then(r => r.json()),
     fetch('image/gallery.php').then(r => r.json())
  ])
  .then(([blogData, galleryData]) => {
     let items = [];

     if (blogData.status === 'success' && blogData.blogs) {
        blogData.blogs.forEach(b => {
           items.push({
              id: b.id,
              type: 'blog',
              title: b.title,
              content: b.content,
              image_path: b.image_path,
              author: b.author,
              date: b.created_at,
              tags: b.tags || '',
              likes_count: b.likes_count || 0,
              user_liked: b.user_liked || 0,
              is_private: b.is_private || 0
           });
        });
     }

     if (galleryData.status === 'success' && galleryData.images) {
        galleryData.images.forEach(img => {
           items.push({
              id: img.id,
              type: 'image',
              title: img.title,
              content: '',
              image_path: img.file_path,
              author: img.owner,
              date: img.uploaded_at,
              tags: img.tags || '',
              likes_count: img.likes_count || 0,
              user_liked: img.user_liked || 0,
              is_private: img.is_private || 0
           });
        });
     }

     items.sort((a, b) => new Date(b.date) - new Date(a.date));
     allFeedItems = items;

     renderCategoryFilters();
     renderFilteredFeed();
  })
  .catch(() => {
     blogsGrid.innerHTML = '<div class="col-span-full text-center py-12 text-rose-600"><p>Could not load platform feed assets from cloud database.</p></div>';
  });
}

function renderCategoryFilters() {
   const filterContainer = document.getElementById('categoryFilters');
   if (!filterContainer) return;

   let tagFrequencies = {};
   allFeedItems.forEach(item => {
      if (item.image_path && item.tags) {
         item.tags.split(',').forEach(tag => {
            let cleaned = tag.trim().toLowerCase();
            if (cleaned) {
               tagFrequencies[cleaned] = (tagFrequencies[cleaned] || 0) + 1;
            }
         });
      }
   });

   let sortedTags = Object.keys(tagFrequencies).sort((a, b) => tagFrequencies[b] - tagFrequencies[a]);
   let top5Tags = sortedTags.slice(0, 5);

   let html = `<button class="px-4 py-2 rounded-xl text-xs font-bold transition-all ${activeTagFilter === 'All' ? 'bg-brand-accent text-[#0f325f] shadow' : 'bg-white text-brand-buttonBlueAlt border border-brand-border hover:bg-gray-100'}" onclick="filterFeed('All')">All Feed</button>`;

   top5Tags.forEach(tag => {
      const formattedTag = tag.charAt(0).toUpperCase() + tag.slice(1);
      const isSelected = activeTagFilter === tag;
      html += `<button class="px-4 py-2 rounded-xl text-xs font-bold transition-all ${isSelected ? 'bg-brand-accent text-[#0f325f] shadow' : 'bg-white text-brand-buttonBlueAlt border border-brand-border hover:bg-gray-100'}" onclick="filterFeed('${tag}')">#${formattedTag}</button>`;
   });

   filterContainer.innerHTML = html;
}

function filterFeed(tag) {
   activeTagFilter = tag.toLowerCase();
   renderCategoryFilters();
   renderFilteredFeed();
}

function toggleLike(id, type, buttonElement) {
  if (!isUserLoggedIn()) {
     alert("You must be logged in to like posts.");
     return;
  }

  fetch('blog/toggle_like.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify({ target_id: id, type: type })
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
        const heartIcon = buttonElement.querySelector('i');
        const countSpan = buttonElement.querySelector('.like-count');
        
        countSpan.textContent = data.new_count;
        if (data.like_status === 'liked') {
           heartIcon.className = 'fa-solid fa-heart text-rose-500';
           buttonElement.classList.add('text-rose-500');
        } else {
           heartIcon.className = 'fa-regular fa-heart text-gray-400';
           buttonElement.classList.remove('text-rose-500');
        }
        showToast(`Asset successfully ${data.like_status}!`);
     } else {
        alert(data.message);
     }
  });
}

function shareContent(id, type) {
  const permalink = `${window.location.origin}${window.location.pathname.replace('index.html', '')}${type === 'blog' ? 'view-blog.html?id=' + id : 'gallery.html?id=' + id}`;
  
  navigator.clipboard.writeText(permalink).then(() => {
     showToast("Link copied directly to your clipboard!");
  }).catch(() => {
     alert(`Share link: ${permalink}`);
  });
}

function openMediaDetail(id) {
  const overlay = document.getElementById('mediaDetailOverlay');
  const container = document.getElementById('mediaOverlayContent');
  if(!overlay || !container) return;

  overlay.classList.remove('hidden');
  container.innerHTML = '<p class="text-center text-gray-400">Loading gallery graphic comments feed...</p>';

  fetch(`image/gallery.php?id=${id}`)
    .then(r => r.json())
    .then(data => {
       if (data.status === 'success') {
          const img = data.image;
          const commentsList = data.comments;

          container.innerHTML = `
             <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div>
                   <div class="w-full rounded-2xl overflow-hidden border border-brand-border bg-gray-50 flex items-center justify-center">
                      <img src="${img.file_path}" class="w-full h-auto object-contain max-h-[450px]" />
                   </div>
                   <h2 class="text-xl font-extrabold text-brand-buttonBlueAlt mt-4 mb-1">${escapeHtml(img.title)}</h2>
                   <p class="text-xs text-teal-500 font-bold uppercase mb-2">Uploaded by @${escapeHtml(img.owner)} • ${img.uploaded_at}</p>
                   ${img.tags ? `<p class="text-sm text-teal-600 font-bold mb-4">#${escapeHtml(img.tags.replace(/,/g, ' #'))}</p>` : ''}
                   
                   <div class="flex items-center gap-3">
                     <button onclick="toggleLike(${img.id}, 'image', this)" class="flex items-center gap-1.5 px-4 py-2 bg-gray-50 rounded-xl text-xs font-bold hover:bg-gray-100 transition-all ${img.user_liked > 0 ? 'text-rose-500' : 'text-gray-500'}">
                        <i class="${img.user_liked > 0 ? 'fa-solid fa-heart text-rose-500' : 'fa-regular fa-heart text-gray-400'}"></i>
                        <span class="like-count">${img.likes_count}</span> Likes
                     </button>
                     <button onclick="shareContent(${img.id}, 'image')" class="flex items-center gap-1.5 px-4 py-2 bg-gray-50 rounded-xl text-xs font-bold text-gray-500 hover:bg-gray-100 transition-all">
                        <i class="fa-solid fa-share-nodes"></i> Share Permalinks
                     </button>
                   </div>
                </div>

                <div class="flex flex-col h-full justify-between">
                   <div>
                      <h3 class="font-extrabold text-brand-buttonBlueAlt mb-4"><i class="fa-solid fa-comments mr-1.5 text-teal-500"></i>Photo Discussion Board</h3>
                      <div class="space-y-3 overflow-y-auto max-h-[300px] mb-4 pr-2" id="imageCommentsList">
                         ${commentsList.length ? commentsList.map(c => `
                            <div class="bg-gray-50 border border-brand-border p-3.5 rounded-xl text-xs flex flex-col gap-1">
                               <strong class="text-[#153861]">@${escapeHtml(c.commentator)}</strong>
                               <p class="text-[#0f2d4e]">${escapeHtml(c.comment_text)}</p>
                            </div>
                         `).join('') : '<p class="text-xs text-gray-400 italic">No comments posted yet.</p>'}
                      </div>
                   </div>

                   ${isUserLoggedIn() ? `
                      <form onsubmit="submitImageComment(event, ${img.id})" class="mt-4 border-t border-brand-border pt-4">
                         <textarea required id="imageCommentInput" rows="2" placeholder="Write a public graphic comment..." class="w-full px-4 py-2.5 border border-brand-border rounded-xl bg-gray-50 text-xs focus:outline-none focus:ring-2 focus:ring-brand-accent/50"></textarea>
                         <button type="submit" class="mt-2 w-full bg-brand-buttonBlue text-white font-bold py-2 rounded-lg hover:bg-brand-buttonBlueAlt text-xs transition-all">Publish Comment</button>
                      </form>
                   ` : '<div class="text-xs text-rose-600 bg-rose-50 p-3 rounded-xl border border-rose-200 mt-4 font-semibold">Please authenticate to write a public discussion reply.</div>'}
                </div>
             </div>
          `;
       } else {
          alert(data.message);
          closeMediaDetail();
       }
    });
}

function submitImageComment(e, imageId) {
  e.preventDefault();
  const textInput = document.getElementById('imageCommentInput');
  const comment_text = textInput.value.trim();

  // EXPLICIT FIX: Add blog_id: null directly into the payload to satisfy strictly typed tables
  fetch('blog/comment.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify({ image_id: imageId, blog_id: null, comment_text: comment_text })
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
        textInput.value = '';
        openMediaDetail(imageId); // Reload dynamic comments view
     } else {
        alert(data.message);
     }
  });
}

function closeMediaDetail() {
  const overlay = document.getElementById('mediaDetailOverlay');
  if(overlay) overlay.classList.add('hidden');
}

function renderFilteredFeed() {
   const blogsGrid = document.getElementById('blogsFeedGrid');
   if (!blogsGrid) return;

   const filtered = allFeedItems.filter(item => {
      if (activeTagFilter === 'all') return true;
      if (!item.tags) return false;
      return item.tags.toLowerCase().split(',').map(t => t.trim()).includes(activeTagFilter);
   });

   const feedCount = document.getElementById('feedCount');
   if (feedCount) {
      feedCount.textContent = `Showing ${filtered.length} of ${allFeedItems.length} items`;
   }

   if (filtered.length === 0) {
      blogsGrid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-400"><p>No items match this category tag.</p></div>';
      return;
   }

   blogsGrid.innerHTML = filtered.map(item => {
      const isLiked = item.user_liked > 0;
      const privacyLabel = item.is_private == 1 ? '<span class="text-xs text-rose-500 font-bold ml-1">🔒 Private</span>' : '';
      
      if (item.type === 'blog') {
         return `
            <article class="bg-white rounded-[24px] overflow-hidden border border-brand-border shadow-md flex flex-col justify-between transition-all duration-300 transform hover:-translate-y-1 relative">
               <div class="absolute top-4 left-4 z-10 bg-brand-buttonBlue text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow flex items-center gap-1">
                  <i class="fa-solid fa-feather-pointed"></i> Blog Post
               </div>
               <div>
                 ${item.image_path ? `<div class="h-44 w-full overflow-hidden rounded-t-2xl"><img src="${item.image_path}" class="w-full h-full object-cover" /></div>` : ''}
                 <div class="p-6">
                   <h3 class="text-lg font-extrabold mb-1 truncate text-brand-buttonBlueAlt mt-4">${escapeHtml(item.title)} ${privacyLabel}</h3>
                   <span class="text-xs font-bold text-teal-500 block mb-4 uppercase tracking-wider">By @${escapeHtml(item.author)} • ${item.date}</span>
                   <p class="text-xs text-gray-500 line-clamp-3 leading-relaxed mb-4">${escapeHtml(item.content)}</p>
                   ${item.tags ? `
                      <div class="flex flex-wrap gap-1 mb-2">
                         ${item.tags.split(',').map(t => `<span class="text-[10px] bg-teal-50 text-teal-700 font-bold px-2 py-1 rounded hover:bg-teal-100 cursor-pointer" onclick="filterFeed('${t.trim()}')">#${t.trim()}</span>`).join('')}
                      </div>
                   ` : ''}
                 </div>
               </div>
               <div class="p-6 pt-0 flex items-center justify-between border-t border-brand-border pt-4">
                 <a class="text-brand-accent font-bold text-xs hover:underline flex items-center gap-1.5 text-teal-600" href="view-blog.html?id=${item.id}">View Post & Comment <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                 <div class="flex items-center gap-2">
                   <button onclick="toggleLike(${item.id}, 'blog', this)" class="flex items-center gap-1 text-xs font-bold text-gray-500 ${isLiked ? 'text-rose-500' : ''}">
                      <i class="${isLiked ? 'fa-solid fa-heart text-rose-500' : 'fa-regular fa-heart text-gray-400'}"></i>
                      <span class="like-count">${item.likes_count}</span>
                   </button>
                   <button onclick="shareContent(${item.id}, 'blog')" class="text-gray-400 hover:text-brand-buttonBlue text-xs"><i class="fa-solid fa-share-nodes"></i></button>
                 </div>
               </div>
            </article>
         `;
      } else {
         return `
            <article class="bg-white rounded-[24px] overflow-hidden border border-brand-border shadow-md flex flex-col justify-between transition-all duration-300 transform hover:-translate-y-1 relative">
               <div class="absolute top-4 left-4 z-10 bg-teal-500 text-brand-buttonBlueAlt text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow flex items-center gap-1">
                  <i class="fa-solid fa-image"></i> Gallery Upload
               </div>
               <div>
                 <div class="h-44 w-full overflow-hidden rounded-t-2xl cursor-pointer" onclick="openMediaDetail(${item.id})"><img src="${item.image_path}" class="w-full h-full object-cover" /></div>
                 <div class="p-6">
                   <h3 class="text-lg font-extrabold mb-1 truncate text-brand-buttonBlueAlt mt-4">${escapeHtml(item.title)} ${privacyLabel}</h3>
                   <span class="text-xs font-bold text-teal-500 block mb-4 uppercase tracking-wider">Uploaded by @${escapeHtml(item.author)} • ${item.date}</span>
                   ${item.tags ? `
                      <div class="flex flex-wrap gap-1">
                         ${item.tags.split(',').map(t => `<span class="text-[10px] bg-teal-50 text-teal-700 font-bold px-2 py-1 rounded hover:bg-teal-100 cursor-pointer" onclick="filterFeed('${t.trim()}')">#${t.trim()}</span>`).join('')}
                      </div>
                   ` : ''}
                 </div>
               </div>
               <div class="p-6 pt-0 flex items-center justify-between border-t border-brand-border pt-4">
                  <button onclick="openMediaDetail(${item.id})" class="text-brand-accent font-bold text-xs hover:underline flex items-center gap-1.5 text-teal-600">Discuss Image <i class="fa-solid fa-comments"></i></button>
                  <div class="flex items-center gap-2">
                   <button onclick="toggleLike(${item.id}, 'image', this)" class="flex items-center gap-1 text-xs font-bold text-gray-500 ${isLiked ? 'text-rose-500' : ''}">
                      <i class="${isLiked ? 'fa-solid fa-heart text-rose-500' : 'fa-regular fa-heart text-gray-400'}"></i>
                      <span class="like-count">${item.likes_count}</span>
                   </button>
                   <button onclick="shareContent(${item.id}, 'image')" class="text-gray-400 hover:text-brand-buttonBlue text-xs"><i class="fa-solid fa-share-nodes"></i></button>
                 </div>
               </div>
            </article>
         `;
      }
   }).join('');
}

function isUserLoggedIn() {
  return sessionStorage.getItem('picstoreLoggedIn') === 'true';
}

function logoutUser() {
  fetch('auth/logout.php')
    .then(() => {
       sessionStorage.clear();
       window.location.href = 'login.html';
    });
}

function updateAuthUI() {
  const loggedIn = isUserLoggedIn();
  const username = sessionStorage.getItem('picstoreUsername');
  const role = sessionStorage.getItem('picstoreRole');

  const authMenuBtn = document.getElementById('authMenuBtn');
  const authMenuBtnText = document.getElementById('authMenuBtnText');
  const registerMenuBtn = document.getElementById('registerMenuBtn');
  const navAdminBtn = document.getElementById('navAdminBtn');

  if (loggedIn) {
     if (authMenuBtn) {
        authMenuBtn.onclick = logoutUser;
        authMenuBtn.href = 'javascript:void(0)';
     }
     if (authMenuBtnText) authMenuBtnText.textContent = 'Logout';
     if (registerMenuBtn) registerMenuBtn.style.display = 'none';
     if (role === 'Administrator' && navAdminBtn) navAdminBtn.classList.remove('hidden');
  } else {
     if (authMenuBtn) {
        authMenuBtn.onclick = null;
        authMenuBtn.href = 'login.html';
     }
     if (authMenuBtnText) authMenuBtnText.textContent = 'Sign In';
     if (registerMenuBtn) registerMenuBtn.style.display = '';
     if (navAdminBtn) navAdminBtn.classList.add('hidden');
  }

  const authNotice = document.getElementById('authNotice');
  if (authNotice) {
    if (!loggedIn) {
       authNotice.classList.remove('hidden');
       authNotice.innerHTML = '⚠️ Access Denied: Please log in or register to publish content.';
    } else {
       authNotice.classList.add('hidden');
    }
  }

  const blogForm = document.getElementById('blogForm');
  const galleryForm = document.getElementById('galleryAddForm');
  if (blogForm) {
    const submitButton = blogForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = !loggedIn;
  }
  if (galleryForm) {
    const submitButton = galleryForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = !loggedIn;
  }
}

function escapeHtml(text) {
  if (!text) return '';
  return text.replace(/[&<>"'`]/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
    '`': '&#96;'
  }[char] || char));
}
CODE
        );

        // --- VALIDATION.JS ---
        file_put_contents('js/validation.js', <<<'CODE'
function validateLogin() {
  const username = document.getElementById('loginUsername').value.trim();
  const password = document.getElementById('loginPassword').value.trim();
  
  if (!username || !password) {
    alert('Please complete all form credentials.');
    return false;
  }

  fetch('auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
       sessionStorage.setItem('picstoreLoggedIn', 'true');
       sessionStorage.setItem('picstoreUsername', data.user.username);
       sessionStorage.setItem('picstoreEmail', data.user.email);
       sessionStorage.setItem('picstoreRole', data.user.role);
       sessionStorage.setItem('picstoreMemberSince', data.user.member_since);
       alert('Logged in successfully!');
       window.location.href = 'profile.html';
     } else {
       alert(data.message);
     }
  })
  .catch(err => alert("An error occurred during authentication setup."));

  return false;
}

function validateRegister() {
  const username = document.getElementById('registerUsername').value.trim();
  const email = document.getElementById('registerEmail').value.trim();
  const password = document.getElementById('registerPassword').value;
  const confirm = document.getElementById('registerConfirm').value;

  if (!username || !email || !password || !confirm) {
    alert('Please enter all parameters to proceed.');
    return false;
  }
  if (password !== confirm) {
    alert('Input passwords do not match!');
    return false;
  }

  fetch('auth/register.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, email, password })
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
       sessionStorage.setItem('picstoreLoggedIn', 'true');
       sessionStorage.setItem('picstoreUsername', data.user.username);
       sessionStorage.setItem('picstoreEmail', data.user.email);
       sessionStorage.setItem('picstoreRole', data.user.role);
       sessionStorage.setItem('picstoreMemberSince', data.user.member_since);
       alert('Account created and logged in!');
       window.location.href = 'profile.html';
     } else {
       alert(data.message);
     }
  })
  .catch(err => alert("Registration communication failed."));

  return false;
}

function validateBlog() {
  if (sessionStorage.getItem('picstoreLoggedIn') !== 'true') {
    alert('Authentication required. Redirecting to login page.');
    window.location.href = 'login.html';
    return false;
  }

  const title = document.getElementById('blogTitle').value.trim();
  const tags = document.getElementById('tags').value.trim();
  const isPrivate = document.getElementById('isPrivate').value;
  const content = document.getElementById('blogContent').value.trim();
  const imageInput = document.getElementById('blogImage');

  if (!title || !content) {
    alert('Please complete both a title and article body.');
    return false;
  }

  const formData = new FormData();
  formData.append('title', title);
  formData.append('tags', tags);
  formData.append('is_private', isPrivate);
  formData.append('content', content);
  
  if (imageInput.files && imageInput.files[0]) {
     formData.append('blog_image', imageInput.files[0]);
  }

  fetch('blog/create_blog.php', {
     method: 'POST',
     body: formData
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
        alert(data.message);
        window.location.href = 'index.html';
     } else {
        alert(data.message);
     }
  })
  .catch(() => alert("Failed to connect to the blogging publish server."));

  return false;
}
CODE
        );

        // --- UPLOAD.JS ---
        file_put_contents('js/upload.js', <<<'CODE'
function previewImage(event) {
  const preview = document.getElementById('imagePreview');
  const aiBtn = document.getElementById('aiBlogTagBtn');
  const file = event.target.files && event.target.files[0];
  if (!file) {
    preview.innerHTML = 'No image selected';
    if (aiBtn) aiBtn.classList.add('hidden');
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    preview.innerHTML = `<img src="${reader.result}" alt="Preview" style="max-height:160px; object-fit:contain;">`;
    if (aiBtn) aiBtn.classList.remove('hidden');
  };
  reader.readAsDataURL(file);
}

function previewGalleryImage(event) {
  const preview = document.getElementById('galleryAddPreview');
  const aiBtn = document.getElementById('aiGalleryTagBtn');
  const file = event.target.files && event.target.files[0];
  if (!preview) return;

  if (!file) {
    preview.innerHTML = 'No image selected';
    if (aiBtn) aiBtn.classList.add('hidden');
    return;
  }

  const reader = new FileReader();
  reader.onload = () => {
    preview.innerHTML = `<img src="${reader.result}" alt="Selected gallery preview">`;
    if (aiBtn) aiBtn.classList.remove('hidden');
  };
  reader.readAsDataURL(file);
}

async function generateAITags(fileInputId, targetInputId, buttonId) {
  const fileInput = document.getElementById(fileInputId);
  const targetInput = document.getElementById(targetInputId);
  const button = document.getElementById(buttonId);

  if (!fileInput || !fileInput.files || !fileInput.files[0]) {
    alert("Please select an image file first.");
    return;
  }

  const file = fileInput.files[0];
  const originalHtml = button.innerHTML;
  
  button.disabled = true;
  button.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> AI is analyzing...`;

  // OPTIONAL: Developers can paste their actual Google Gemini API key here
  const apiKey = ""; 

  // Simulate premium AI processing latency
  await new Promise(resolve => setTimeout(resolve, 1200));

  try {
    if (apiKey && apiKey.trim() !== "") {
      // MODE A: Live Google Gemini 2.5 Flash API execution
      const base64Data = await fileToBase64(file);
      const mimeType = file.type || "image/png";
      const rawBase64 = base64Data.split(",")[1];

      const payload = {
        contents: [{
          role: "user",
          parts: [
            { text: "Analyze this image and generate 4 to 6 descriptive, single-word tags separated by commas. Do not include spaces, hash symbols, capital letters, or punctuation. Only return the raw comma-separated list of tags and absolutely nothing else. Example response: sunset,ocean,sky,beach" },
            { inlineData: { mimeType: mimeType, data: rawBase64 } }
          ]
        }]
      };

      const result = await callGeminiWithBackoff(payload, apiKey);
      const tagsResult = result.candidates?.[0]?.content?.parts?.[0]?.text;

      if (tagsResult) {
        const cleanTags = tagsResult.trim().replace(/\s+/g, '').toLowerCase();
        targetInput.value = cleanTags;
        showToast("✨ Gemini AI tags generated successfully!");
      } else {
        throw new Error("Unable to parse a clean tags structure from the vision model.");
      }
    } else {
      // MODE B: Smart Local Heuristic Tagging Engine fallback
      const titleElementId = fileInputId === 'blogImage' ? 'blogTitle' : 'galleryImageTitle';
      const titleInput = document.getElementById(titleElementId);
      const titleText = titleInput ? titleInput.value.trim().toLowerCase() : "";
      const fileNameText = file.name.toLowerCase();
      
      const combinedText = (titleText + " " + fileNameText).replace(/[^a-z0-9\s]/g, " ");
      const words = combinedText.split(/\s+/);
      
      let tags = new Set();
      
      // High-Fidelity Local Mapping Dictionary
      const dictionary = {
        nature: ["nature", "outdoors", "scenery", "landscape"],
        sunset: ["sunset", "sky", "dusk", "evening", "beautiful"],
        sunrise: ["sunrise", "morning", "sky", "sunlight"],
        cloud: ["clouds", "sky", "weather", "atmosphere"],
        server: ["server", "datacenter", "vmware", "cloud", "technology", "network"],
        database: ["database", "sql", "data", "storage", "backend"],
        code: ["coding", "programming", "developer", "software", "tech"],
        food: ["food", "delicious", "culinary", "gourmet"],
        travel: ["travel", "adventure", "explore", "vacation"],
        minimalist: ["minimalist", "clean", "aesthetic", "modern"],
        design: ["design", "graphics", "abstract", "creative"],
        cat: ["cat", "pet", "animal", "cute", "feline"],
        dog: ["dog", "pet", "animal", "cute", "canine"]
      };
      
      // Scan for dictionary matching triggers
      Object.keys(dictionary).forEach(key => {
        if (combinedText.includes(key)) {
          dictionary[key].forEach(tag => tags.add(tag));
        }
      });
      
      // Add descriptive long words from inputs
      words.forEach(word => {
        if (word.length > 3 && !["with", "this", "that", "blog", "image", "post", "test", "file", "photo", "upload"].includes(word)) {
          tags.add(word);
        }
      });
      
      // Fill defaults if too short
      if (tags.size < 3) {
        const defaults = ["creative", "cloud", "cms", "picstore", "portfolio", "aesthetic"];
        defaults.forEach(def => tags.add(def));
      }
      
      const finalTags = Array.from(tags).slice(0, 5).join(",");
      targetInput.value = finalTags;
      showToast("✨ Local Smart AI tags generated successfully!");
    }
  } catch (err) {
    alert("AI Tagging Error: " + err.message + ". Please input search tags manually.");
  } finally {
    button.disabled = false;
    button.innerHTML = originalHtml;
  }
}

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = () => resolve(reader.result);
    reader.onerror = error => reject(error);
  });
}

async function callGeminiWithBackoff(payload, apiKey) {
  const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=${apiKey}`;
  const delays = [1000, 2000, 4000, 8000, 16000];

  for (let attempt = 0; attempt <= 5; attempt++) {
    try {
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      return result;
    } catch (error) {
      if (attempt === 5) {
        throw error; 
      }
      await new Promise(resolve => setTimeout(resolve, delays[attempt]));
    }
  }
}

function addGalleryImage(event) {
  event.preventDefault();
  if (sessionStorage.getItem('picstoreLoggedIn') !== 'true') {
     alert("You must be logged in to upload images.");
     return false;
  }

  const fileInput = document.getElementById('galleryUpload');
  const titleInput = document.getElementById('galleryImageTitle');
  const tagsInput = document.getElementById('galleryImageTags');
  const privacyInput = document.getElementById('galleryIsPrivate');

  if (!fileInput.files || !fileInput.files[0]) {
     alert("Please choose a file to proceed.");
     return false;
  }

  const formData = new FormData();
  formData.append('gallery_file', fileInput.files[0]);
  formData.append('title', titleInput.value.trim());
  formData.append('tags', tagsInput.value.trim());
  formData.append('is_private', privacyInput.value);

  fetch('image/upload.php', {
     method: 'POST',
     body: formData
  })
  .then(r => r.json())
  .then(data => {
     if (data.status === 'success') {
        alert(data.message);
        fileInput.value = '';
        titleInput.value = '';
        tagsInput.value = '';
        document.getElementById('galleryAddPreview').innerHTML = 'No image selected';
        const aiBtn = document.getElementById('aiGalleryTagBtn');
        if (aiBtn) aiBtn.classList.add('hidden');
        loadGalleryGrid();
     } else {
        alert(data.message);
     }
  })
  .catch(() => alert("An error occurred during file transfer."));

  return false;
}

function loadGalleryGrid() {
  const grid = document.getElementById('galleryGrid');
  if (!grid) return;

  fetch('image/gallery.php')
    .then(r => r.json())
    .then(data => {
       if (data.status === 'success') {
          grid.innerHTML = data.images.length
             ? data.images.map(i => `
                <div class="bg-white border border-brand-border rounded-2xl overflow-hidden shadow-sm hover:shadow-lg transition-all flex flex-col justify-between">
                   <div class="h-44 overflow-hidden bg-gray-50 flex items-center justify-center cursor-pointer" onclick="openMediaDetail(${i.id})">
                      <img src="${i.file_path}" style="width:100%; height:180px; object-fit:cover;" alt="${escapeHtml(i.title)}" />
                   </div>
                   <div class="p-4 flex flex-col justify-between">
                      <div>
                         <strong class="text-brand-buttonBlue block truncate text-sm mb-1">${escapeHtml(i.title)} ${i.is_private == 1 ? '<span class="text-xs text-rose-500 font-bold">🔒 Private</span>' : ''}</strong>
                         <span class="text-[10px] text-gray-400 block font-semibold mb-2 uppercase">Uploaded by @${escapeHtml(i.owner)}</span>
                      </div>
                      ${i.tags ? `<p class="text-teal-500 font-bold text-xs mt-1">#${escapeHtml(i.tags.replace(/,/g, ' #'))}</p>` : ''}
                   </div>
                </div>
             `).join('')
             : '<div class="col-span-full text-center py-12 text-gray-400"><p>No images uploaded yet.</p></div>';
          
          const params = new URLSearchParams(window.location.search);
          const sharedImageId = parseInt(params.get('id') || 0);
          if (sharedImageId > 0 && typeof openMediaDetail === 'function') {
             openMediaDetail(sharedImageId);
          }
       }
    });
}

if (window.location.pathname.endsWith('gallery.html')) {
   document.addEventListener('DOMContentLoaded', loadGalleryGrid);
}
CODE
        );

        // --- SEARCH.JS ---
        file_put_contents('js/search.js', <<<'CODE'
function searchContent(searchFieldId) {
  const input = document.getElementById(searchFieldId);
  if (!input) return;

  const query = input.value.trim();
  if (!query) {
    alert('Please enter a query string.');
    return;
  }

  if (!window.location.pathname.endsWith('search.html')) {
     window.location.href = `search.html?q=${encodeURIComponent(query)}`;
     return;
  }

  executeGlobalSearch(query);
}

function executeGlobalSearch(query) {
  const container = document.getElementById('searchResults');
  if (!container) return;

  container.innerHTML = `<div class="text-gray-400">Searching cloud nodes for "${escapeHtml(query)}"...</div>`;

  fetch(`api/search.php?q=${encodeURIComponent(query)}`)
    .then(r => r.json())
    .then(data => {
       if (data.status === 'success') {
          let html = `<h2 class="text-xl font-extrabold text-brand-buttonBlue mb-6">Search Results for: "${escapeHtml(query)}"</h2>`;

          // Blogs Results
          html += `<div class="mb-8"><h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4 border-b pb-2"><i class="fa-solid fa-feather mr-2 text-teal-500"></i>Blogs Matched (${data.blogs.length})</h3>`;
          if (data.blogs.length) {
             html += `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">` + data.blogs.map(b => `
                <div class="border border-brand-border p-5 rounded-2xl bg-gray-50 flex flex-col justify-between">
                   <div>
                      <h4 class="font-bold text-brand-buttonBlue text-base mb-1">${escapeHtml(b.title)}</h4>
                      <p class="text-xs text-gray-400 font-bold mb-3">Published by @${escapeHtml(b.author)}</p>
                      <p class="text-gray-500 text-xs line-clamp-2">${escapeHtml(b.content)}</p>
                   </div>
                   <a class="text-brand-buttonBlue font-bold text-xs hover:underline mt-4 text-left" href="view-blog.html?id=${b.id}">Read Full Article <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
             `).join('') + `</div>`;
          } else {
             html += '<p class="text-xs text-gray-400">No matching blog posts found.</p>';
          }
          html += `</div>`;

          // Images Results
          html += `<div class="mb-8"><h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4 border-b pb-2"><i class="fa-solid fa-images mr-2 text-teal-500"></i>Gallery Images Matched (${data.images.length})</h3>`;
          if (data.images.length) {
             html += `<div class="grid grid-cols-2 md:grid-cols-4 gap-4">` + data.images.map(i => `
                <div class="border border-brand-border rounded-xl overflow-hidden bg-gray-50 shadow-sm">
                   <div class="h-28 overflow-hidden"><img src="${i.file_path}" class="w-full h-full object-cover" /></div>
                   <div class="p-3 text-xs">
                      <strong class="block truncate text-brand-buttonBlue">${escapeHtml(i.title)}</strong>
                      <p class="text-[10px] text-gray-400 mt-1">Uploaded by @${escapeHtml(i.owner)}</p>
                   </div>
                </div>
             `).join('') + `</div>`;
          } else {
             html += '<p class="text-xs text-gray-400">No matching gallery assets found.</p>';
          }
          html += `</div>`;

          // Users Results
          html += `<div><h3 class="text-lg font-bold text-brand-buttonBlueAlt mb-4 border-b pb-2"><i class="fa-solid fa-users mr-2 text-teal-500"></i>Creators Matched (${data.users.length})</h3>`;
          if (data.users.length) {
             html += `<div class="grid grid-cols-1 md:grid-cols-3 gap-4">` + data.users.map(u => `
                <div class="border border-brand-border p-4 rounded-xl bg-gray-50 text-xs flex items-center gap-3">
                   <div class="w-10 h-10 rounded-lg bg-teal-500 flex items-center justify-center text-white font-extrabold uppercase">${u.username.slice(0, 2)}</div>
                   <div>
                      <strong class="text-brand-buttonBlue">${escapeHtml(u.username)}</strong>
                      <p class="text-gray-400 font-medium">Joined: ${u.created_at}</p>
                   </div>
                </div>
             `).join('') + `</div>`;
          } else {
             html += '<p class="text-xs text-gray-400">No creators matched your query.</p>';
          }
          html += `</div>`;

          container.innerHTML = html;
       }
    })
    .catch(() => {
       container.innerHTML = '<div class="text-rose-600">Failed to fetch server data.</div>';
    });
}

document.addEventListener('DOMContentLoaded', () => {
   if (window.location.pathname.endsWith('search.html')) {
      const params = new URLSearchParams(window.location.search);
      const query = params.get('q');
      if (query) {
         const searchInput = document.getElementById('mainSearch');
         if (searchInput) searchInput.value = query;
         executeGlobalSearch(query);
      } else {
         const container = document.getElementById('searchResults');
         if (container) container.innerHTML = '<p class="text-gray-400">Please enter a keyword into the top bar to begin search.</p>';
      }
   }
});
CODE
        );

        $setup_completed = true;
        $setup_message = "All files extracted, database schema active, and server scripts successfully structured!";

    } catch (Exception $e) {
        $setup_error = true;
        $setup_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PICSTORE | Monolithic System Installer</title>
  <style>
     body {
       font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
       background: #0f2b56;
       color: #eef6ff;
       margin: 0;
       padding: 0;
       display: flex;
       justify-content: center;
       align-items: center;
       min-height: 100vh;
     }
     .setup-box {
       background: #ffffff;
       color: #0f2d4e;
       padding: 40px;
       border-radius: 28px;
       box-shadow: 0 20px 60px rgba(0,0,0,0.3);
       width: 100%;
       max-width: 580px;
       box-sizing: border-box;
     }
     h1 {
       color: #0f325f;
       margin-top: 0;
       font-size: 2.2rem;
       border-bottom: 2px solid #e7f7fb;
       padding-bottom: 15px;
     }
     label {
       display: block;
       margin-top: 20px;
       font-weight: 600;
       color: #153861;
     }
     input, select {
       width: 100%;
       padding: 12px 14px;
       border-radius: 12px;
       border: 1px solid #7a91b0;
       background: #f7fafb;
       margin-top: 8px;
       box-sizing: border-box;
       font-size: 1rem;
     }
     button {
       width: 100%;
       padding: 16px;
       border-radius: 14px;
       background: linear-gradient(180deg, #26d4c5 0%, #1bd0c1 100%);
       color: #0f325f;
       border: none;
       font-size: 1.1rem;
       font-weight: 700;
       cursor: pointer;
       margin-top: 30px;
       box-shadow: 0 10px 25px rgba(27,208,193,0.3);
       transition: all 0.2s;
     }
     button:hover {
       transform: translateY(-2px);
     }
     .notice {
       padding: 15px;
       border-radius: 12px;
       margin-top: 20px;
       font-weight: 600;
     }
     .notice-success {
       background: #d4edda;
       color: #155724;
       border: 1px solid #c3e6cb;
     }
     .notice-error {
       background: #f8d7da;
       color: #721c24;
       border: 1px solid #f5c6cb;
     }
     .launch-btn {
       background: #0f325f;
       color: #ffffff;
       text-align: center;
       text-decoration: none;
       display: block;
       padding: 15px;
       border-radius: 14px;
       font-weight: bold;
       margin-top: 25px;
     }
     .diag-panel {
       background: #f8f9fa;
       border: 1px solid #e9ecef;
       border-radius: 14px;
       padding: 15px;
       margin-top: 15px;
     }
     .diag-item {
       display: flex;
       justify-content: space-between;
       font-size: 0.85rem;
       margin-bottom: 6px;
     }
     .badge {
       font-weight: bold;
       padding: 2px 8px;
       border-radius: 6px;
     }
     .badge-pass { background: #d4edda; color: #155724; }
     .badge-fail { background: #f8d7da; color: #721c24; }
  </style>
</head>
<body>
  <div class="setup-box">
     <h1>PICSTORE Installer</h1>
     <p>Deploy directories, active dynamic controllers, and initialize schemas on your VM server.</p>

     <div class="diag-panel">
        <div class="diag-item">
           <span>PHP Version &gt;= 8.0:</span>
           <span class="badge <?php echo $diagnostics['php_version'] ? 'badge-pass' : 'badge-fail'; ?>"><?php echo PHP_VERSION; ?></span>
        </div>
        <div class="diag-item">
           <span>Folder Write Permissions:</span>
           <span class="badge <?php echo $diagnostics['write_permissions'] ? 'badge-pass' : 'badge-fail'; ?>"><?php echo $diagnostics['write_permissions'] ? 'Writable' : 'Locked'; ?></span>
        </div>
        <div class="diag-item">
           <span>SQLite Driver Active:</span>
           <span class="badge <?php echo $diagnostics['pdo_sqlite'] ? 'badge-pass' : 'badge-fail'; ?>"><?php echo $diagnostics['pdo_sqlite'] ? 'Ready' : 'Missing'; ?></span>
        </div>
        <div class="diag-item">
           <span>MySQL Driver Active:</span>
           <span class="badge <?php echo $diagnostics['pdo_mysql'] ? 'badge-pass' : 'badge-fail'; ?>"><?php echo $diagnostics['pdo_mysql'] ? 'Ready' : 'Missing'; ?></span>
        </div>
     </div>

     <?php if ($setup_completed): ?>
        <div class="notice notice-success">
           🎉 <?php echo $setup_message; ?>
        </div>
        <a href="index.html" class="launch-btn">🚀 Launch PICSTORE App</a>
     <?php elseif ($setup_error): ?>
        <div class="notice notice-error">
           ⚠️ Error: <?php echo $setup_message; ?>
        </div>
     <?php endif; ?>

     <?php if (!$setup_completed): ?>
     <form method="POST">
        <label for="db_type">Select Platform Database Engine:</label>
        <select name="db_type" id="db_type" onchange="toggleMySQLFields(this.value)">
           <option value="sqlite">SQLite (Instant Setup - No Credentials Required)</option>
           <option value="mysql">MySQL Server (Recommended for VM Production Deployments)</option>
        </select>

        <div id="mysql_fields" style="display:none; border-left: 3px solid #1bd0c1; padding-left: 15px; margin-top: 15px;">
           <label for="mysql_host">MySQL Hostname / IP:</label>
           <input type="text" name="mysql_host" id="mysql_host" value="localhost">

           <label for="mysql_user">Database Username:</label>
           <input type="text" name="mysql_user" id="mysql_user" value="root">

           <label for="mysql_pass">Database Password:</label>
           <input type="password" name="mysql_pass" id="mysql_pass" placeholder="Leave empty if none">

           <label for="mysql_name">Database Name:</label>
           <input type="text" name="mysql_name" id="mysql_name" value="picstore_db">
        </div>

        <button type="submit" name="run_install">Run Setup & Extract Assets</button>
     </form>
     <?php endif; ?>
  </div>

  <script>
     function toggleMySQLFields(val) {
        document.getElementById('mysql_fields').style.display = (val === 'mysql') ? 'block' : 'none';
     }
  </script>
</body>
</html>
