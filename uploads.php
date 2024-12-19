<?php
// ヘッダーとエラーレポート設定
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// データベース接続設定
$host = 'localhost';
$db = 'file_uploads';
$user = 'root';
$password = '';
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("データベース接続に失敗しました: " . $e->getMessage());
}

// 一時フォルダとアートワーク保存フォルダの設定
$tempDir = __DIR__ . '/temp/';
$artworkDir = __DIR__ . '/temp/artwork/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}
if (!is_dir($artworkDir)) {
    mkdir($artworkDir, 0777, true);
}

// getID3ライブラリをロード
require_once __DIR__ . '/vendor/james-heinrich/getid3/getid3/getid3.php';
$getID3 = new getID3();

// ユーザーのIPアドレス取得関数
function getUserIP() {
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

session_start();

// ファイルアップロード処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['mp3files'])) {
    $uploadedFiles = [];
    $uploadedFilesInfo = []; // DBのidとファイルパスを紐付けるための配列
    $userIP = getUserIP();

    foreach ($_FILES['mp3files']['tmp_name'] as $i => $tmpName) {
        if (is_uploaded_file($tmpName)) {
            $fileName = basename($_FILES['mp3files']['name'][$i]);
            $filePath = $tempDir . $fileName;

            // ファイルを一時フォルダに保存
            if (move_uploaded_file($tmpName, $filePath)) {
                $uploadedFiles[] = $filePath;

                // ID3タグ情報の取得
                $fileInfo = $getID3->analyze($filePath);
                getid3_lib::CopyTagsToComments($fileInfo);

                $title = $fileInfo['comments']['title'][0] ?? '';
                $artist = $fileInfo['comments']['artist'][0] ?? '';
                $album = $fileInfo['comments']['album'][0] ?? '';
                $year = $fileInfo['comments']['year'][0] ?? '';
                $genre = $fileInfo['comments']['genre'][0] ?? '';

                // アートワークの取得と保存
                $artworkPath = null;
                if (!empty($fileInfo['comments']['picture'][0]['data'])) {
                    $artworkData = $fileInfo['comments']['picture'][0]['data'];
                    $artworkMime = $fileInfo['comments']['picture'][0]['image_mime'] ?? 'image/jpeg';
                    $artworkExtension = ($artworkMime === 'image/png') ? 'png' : 'jpg';

                    // アートワークを保存
                    $artworkFileName = pathinfo($fileName, PATHINFO_FILENAME) . "_artwork.$artworkExtension";
                    $artworkFullPath = $artworkDir . $artworkFileName;

                    file_put_contents($artworkFullPath, $artworkData);
                    $artworkPath = 'temp/artwork/' . $artworkFileName; // 相対パスとして保存
                }

                // データベースにアップロード履歴とID3タグ情報、Artworkパスを保存
                $stmt = $pdo->prepare("
                    INSERT INTO uploaded_files (file_name, file_path, uploaded_at, ip_address, title, artist, album, year, genre, artwork_path)
                    VALUES (:file_name, :file_path, NOW(), :ip_address, :title, :artist, :album, :year, :genre, :artwork_path)
                ");
                $stmt->execute([
                    ':file_name' => $fileName,
                    ':file_path' => $filePath,
                    ':ip_address' => $userIP,
                    ':title' => $title,
                    ':artist' => $artist,
                    ':album' => $album,
                    ':year' => $year,
                    ':genre' => $genre,
                    ':artwork_path' => $artworkPath,
                ]);

                // 挿入したレコードのIDを取得
                $lastInsertId = $pdo->lastInsertId();
                // uploadedFilesInfo にレコードIDとfilePathを記録
                $uploadedFilesInfo[] = [
                    'id' => $lastInsertId,
                    'filePath' => $filePath
                ];
            }
        }
    }

    // セッションにアップロードファイルを保存
    $_SESSION['uploadedFiles'] = $uploadedFiles;
    $_SESSION['uploadedFilesInfo'] = $uploadedFilesInfo;

    // 編集画面へリダイレクト
    header("Location: edit.php");
    exit;
}
require_once __DIR__ . '/header.php'; 
?>
    <div class="max-w-lg mx-auto bg-white p-8 rounded-lg shadow-lg mt-10">
        <h1 class="text-2xl font-bold text-pink-700 mb-8">Upload MP3 Files</h1>
        <form method="post" action="" enctype="multipart/form-data" class="space-y-4">
            <input type="file" name="mp3files[]" multiple accept=".mp3"
                   id="fileInput"
                   class="block w-full text-sm text-gray-500
                          file:mr-4 file:py-2 file:px-4 file:rounded file:border-0
                          file:text-sm file:bg-pink-100 file:text-pink-700
                          hover:file:bg-pink-200 focus:outline-none focus:ring-2 focus:ring-pink-500">

            <button type="submit" id="uploadButton" disabled
                    class="px-6 py-2 bg-pink-500 text-white font-semibold rounded hover:bg-pink-600 disabled:opacity-50 disabled:cursor-not-allowed">
                Upload
            </button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const fileInput = document.getElementById('fileInput');
            const uploadButton = document.getElementById('uploadButton');
            
            fileInput.addEventListener('change', () => {
                // 選択されたファイルが1つ以上ある場合、ボタンを有効化
                if (fileInput.files.length > 0) {
                    uploadButton.disabled = false;
                } else {
                    uploadButton.disabled = true;
                }
            });
        });
    </script>
</body>
</html>