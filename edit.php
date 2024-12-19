<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/james-heinrich/getid3/getid3/getid3.php';
require_once __DIR__ . '/vendor/james-heinrich/getid3/getid3/write.php';

use getid3_writetags;
$getID3 = new getID3();

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
    die("DB Connection Failed: " . $e->getMessage());
}

$tempDir = __DIR__ . '/temp/artwork';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0775, true);
}
if (!is_writable($tempDir)) {
    $permissions = substr(sprintf('%o', fileperms($tempDir)), -4);
    $ownerInfo = posix_getpwuid(fileowner($tempDir));
    die("Directory is not writable: $tempDir. Current permissions: $permissions. Owner: " . $ownerInfo['name']);
}

$uploadedFiles = $_SESSION['uploadedFiles'] ?? [];
$uploadedFilesInfo = $_SESSION['uploadedFilesInfo'] ?? [];

if (empty($uploadedFiles) || empty($uploadedFilesInfo)) {
    echo "<h2>No files have been uploaded.</h2>";
    exit;
}

// タグ更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['files']) && isset($_POST['ids'])) {
    // POST受信時にファイル名も取り出す
    $filenames = $_POST['filename'] ?? [];
    $files = $_POST['files'];
    $ids   = $_POST['ids'];
    $titles = $_POST['title'] ?? [];
    $artists = $_POST['artist'] ?? [];
    $albums = $_POST['album'] ?? [];
    $years = $_POST['year'] ?? [];
    $genres = $_POST['genre'] ?? [];
    $existingArtworks = $_POST['existing_artwork'] ?? [];
    $artworks = $_FILES['artwork'] ?? [];
    $editedFiles = [];

    foreach ($files as $index => $filePath) {
        $recordId = $ids[$index];

        // ファイルが存在しない場合はDBのみ更新可能
        if (!file_exists($filePath)) {
            // ファイルが無い場合でもDBのテキストタグ更新
            $updateStmt = $pdo->prepare("
                UPDATE uploaded_files
                SET file_name=:file_name, title=:title, artist=:artist, album=:album, year=:year, genre=:genre
                WHERE id=:id
            ");
            $updateStmt->execute([
                ':file_name' => $filenames[$index],
                ':title' => $titles[$index],
                ':artist' => $artists[$index],
                ':album' => $albums[$index],
                ':year' => $years[$index],
                ':genre' => $genres[$index],
                ':id' => $recordId
            ]);
            
            // 新規アートワークアップロードがあればDB更新
            if (isset($artworks['tmp_name'][$index]) && is_uploaded_file($artworks['tmp_name'][$index])) {
                // アートワークファイルを保存
                $artworkData = file_get_contents($artworks['tmp_name'][$index]);
                $mimeType = mime_content_type($artworks['tmp_name'][$index]);
                $extension = ($mimeType === 'image/png') ? 'png' : 'jpg';
                $artworkFileName = uniqid("artwork_") . ".$extension"; 
                $artworkPath = 'temp/artwork/' . $artworkFileName;
                file_put_contents(__DIR__ . '/' . $artworkPath, $artworkData);

                $updateArtworkStmt = $pdo->prepare("
                    UPDATE uploaded_files SET artwork_path=:artwork_path WHERE id=:id
                ");
                $updateArtworkStmt->execute([
                    ':artwork_path' => $artworkPath,
                    ':id' => $recordId
                ]);
            }

            continue; // ファイルがないのでID3更新はスキップ
        }

        // ファイルがある場合はID3タグを更新
        $tagwriter = new getid3_writetags();
        $tagwriter->filename = $filePath;
        $tagwriter->tagformats = ['id3v2.4'];
        $tagwriter->overwrite_tags = true;
        $tagwriter->tag_encoding = 'UTF-8';

        $tagData = [
            'title' => [$titles[$index]],
            'artist' => [$artists[$index]],
            'album' => [$albums[$index]],
            'year' => [$years[$index]],
            'genre' => [$genres[$index]],
        ];

        // アートワーク処理: 事前に$artworkDataを用意
        $newArtworkData = null;
        $newArtworkMime = 'image/jpeg';

        if (isset($artworks['tmp_name'][$index]) && is_uploaded_file($artworks['tmp_name'][$index])) {
            $newArtworkData = file_get_contents($artworks['tmp_name'][$index]);
            $newArtworkMime = mime_content_type($artworks['tmp_name'][$index]);

            $tagData['attached_picture'][0] = [
                'data' => $newArtworkData,
                'picturetypeid' => 0x03, // Cover (front)
                'description' => 'cover',
                'mime' => $newArtworkMime,
            ];
        } elseif (!empty($existingArtworks[$index])) {
            // 既存アートワークをそのまま利用
            $artworkDataDecoded = base64_decode($existingArtworks[$index]);
            $tagData['attached_picture'][0] = [
                'data' => $artworkDataDecoded,
                'picturetypeid' => 0x03,
                'description' => 'cover',
                'mime' => 'image/jpeg',
            ];
        }

        $tagwriter->tag_data = $tagData;

        if ($tagwriter->WriteTags()) {
            $editedFiles[] = $filePath;

            // DBテキストタグの更新
            $updateStmt = $pdo->prepare("
                UPDATE uploaded_files
                SET file_name=:file_name, title=:title, artist=:artist, album=:album, year=:year, genre=:genre
                WHERE id=:id
            ");
            $updateStmt->execute([
                ':file_name' => $filenames[$index],
                ':title' => $titles[$index],
                ':artist' => $artists[$index],
                ':album' => $albums[$index],
                ':year' => $years[$index],
                ':genre' => $genres[$index],
                ':id' => $recordId
            ]);

            // 新規アートワークがある場合はファイル保存＆DB更新
            if ($newArtworkData !== null) {
                $extension = ($newArtworkMime === 'image/png') ? 'png' : 'jpg';
                $artworkFileName = uniqid("artwork_") . ".$extension";
                $artworkPath = 'temp/artwork/' . $artworkFileName;
                file_put_contents(__DIR__ . '/' . $artworkPath, $newArtworkData);

                $updateArtworkStmt = $pdo->prepare("
                    UPDATE uploaded_files SET artwork_path=:artwork_path WHERE id=:id
                ");
                $updateArtworkStmt->execute([
                    ':artwork_path' => $artworkPath,
                    ':id' => $recordId
                ]);
            }

        } else {
            echo "<p>Error writing tags to file: " . htmlspecialchars($filePath) . "</p>";
            echo "<pre>" . htmlspecialchars(print_r($tagwriter->errors, true)) . "</pre>";
        }
    }

    $_SESSION['editedFiles'] = $editedFiles;

    // ZIP生成
    if (!empty($editedFiles)) {
        $zip = new ZipArchive();
        $zipFilename = __DIR__ . '/temp/edited_files.zip';
        if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($editedFiles as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        }

        // ZIP生成後に元MP3を削除
        foreach ($editedFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    header("Location: edit.php");
    exit;
}

// ダウンロード処理 (ZIP, CSV)
if (isset($_GET['download'])) {
    if ($_GET['download'] === 'zip') {
        $zipFilename = __DIR__ . '/temp/edited_files.zip';
        if (file_exists($zipFilename)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="edited_files.zip"');
            header('Content-Length: ' . filesize($zipFilename));
            readfile($zipFilename);

            // ダウンロード後に一時ファイル削除
            unlink($zipFilename);
            array_map('unlink', glob(__DIR__ . '/temp/artwork/*'));
            exit;
        }
    } elseif ($_GET['download'] === 'csv') {
        $uploadedFilesInfo = $_SESSION['uploadedFilesInfo'] ?? [];
        if (empty($uploadedFilesInfo)) {
            die("No data available for CSV export.");
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="edited_tags.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['filename', 'title', 'artist', 'album', 'year', 'genre']);

        $stmt = $pdo->prepare("SELECT file_name, title, artist, album, year, genre FROM uploaded_files WHERE id=:id");
        foreach ($uploadedFilesInfo as $info) {
            $stmt->execute([':id' => $info['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                fputcsv($output, [
                    $row['file_name'],
                    $row['title'],
                    $row['artist'],
                    $row['album'],
                    $row['year'],
                    $row['genre']
                ]);
            }
        }
        fclose($output);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ID3 Tag Editor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .artwork-container {
            position: relative;
            width: 120px;
            height: 120px;
        }
        .artwork-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
        }
        .artwork-container input[type="file"] {
            display: none;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.artwork-container').forEach(container => {
                const inputFile = container.querySelector('input[type="file"]');
                const imgPreview = container.querySelector('img');

                imgPreview.addEventListener('click', () => inputFile.click());
                inputFile.addEventListener('change', event => {
                    const file = event.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = e => imgPreview.src = e.target.result;
                        reader.readAsDataURL(file);
                    }
                });
            });
        });
    </script>
</head>

<body class="bg-gradient-to-r from-purple-400 via-pink-500 to-red-500 min-h-screen p-6">
    <div class="container mx-auto flex flex-col lg:flex-row justify-between items-center mb-6">
        <div class="text-white font-bold text-3xl hover:text-orange-600">AudioTag Editor Alpha 0.2</div>
    </div>

    <div class="container mx-auto bg-white p-8 rounded-lg shadow-lg">
        <h2 class="text-2xl font-bold text-pink-700 mb-4">Edit ID3 Tags</h2>
        <form method="POST" enctype="multipart/form-data">
            <table class="table-auto w-full border-collapse border border-gray-300 text-sm">
                <thead>
                    <tr class="bg-pink-100 text-pink-700">
                        <th class="border px-3 py-2">File Name</th>
                        <th class="border px-3 py-2">Title</th>
                        <th class="border px-3 py-2">Artist</th>
                        <th class="border px-3 py-2">Album</th>
                        <th class="border px-3 py-2">Year</th>
                        <th class="border px-3 py-2">Genre</th>
                        <th class="border px-3 py-2">Artwork</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // DBからタグ情報を取得して表示
                    foreach ($uploadedFilesInfo as $info) {
                        $recordId = $info['id'];
                        $filePath = $info['filePath'];

                        $stmt = $pdo->prepare("SELECT file_name, title, artist, album, year, genre, artwork_path FROM uploaded_files WHERE id=:id");
                        $stmt->execute([':id' => $recordId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$row) {
                            continue;
                        }

                        $title = $row['title'] ?? '';
                        $artist = $row['artist'] ?? '';
                        $album = $row['album'] ?? '';
                        $year = $row['year'] ?? '';
                        $genre = $row['genre'] ?? '';
                        
                        $artworkBase64 = '';
                        if (!empty($row['artwork_path']) && file_exists(__DIR__ . '/' . $row['artwork_path'])) {
                            $artworkData = file_get_contents(__DIR__ . '/' . $row['artwork_path']);
                            $artworkBase64 = base64_encode($artworkData);
                        }
                    ?>
                    <tr>
                        <td class="border px-3 py-2">
                            <input type="text" name="filename[]" value="<?= htmlspecialchars($row['file_name']) ?>" class="w-full">
                            <input type="hidden" name="files[]" value="<?= htmlspecialchars($filePath) ?>">
                            <input type="hidden" name="ids[]" value="<?= htmlspecialchars($recordId) ?>">
                        </td>
                        <td class="border px-3 py-2"><input type="text" name="title[]" value="<?= htmlspecialchars($title) ?>" class="w-full"></td>
                        <td class="border px-3 py-2"><input type="text" name="artist[]" value="<?= htmlspecialchars($artist) ?>" class="w-full"></td>
                        <td class="border px-3 py-2"><input type="text" name="album[]" value="<?= htmlspecialchars($album) ?>" class="w-full"></td>
                        <td class="border px-3 py-2 w-[60px]"><input type="text" name="year[]" value="<?= htmlspecialchars($year) ?>" class="w-full"></td>
                        <td class="border px-3 py-2 w-[100px]"><input type="text" name="genre[]" value="<?= htmlspecialchars($genre) ?>" class="w-full"></td>
                        <td class="border px-3 py-2 m-auto w-[150px]">
                            <div class="artwork-container">
                                <img src="<?= $artworkBase64 ? 'data:image/jpeg;base64,' . $artworkBase64 : 'placeholder.jpg' ?>" alt="Artwork">
                                <input type="file" name="artwork[]" accept="image/*">
                                <input type="hidden" name="existing_artwork[]" value="<?= $artworkBase64 ?>">
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <button type="submit" class="mt-4 px-6 py-2 bg-pink-500 text-white font-semibold rounded hover:bg-pink-600">Save Tags</button>
        </form>

        <?php if (file_exists(__DIR__ . '/temp/edited_files.zip')): ?>
            <div class="mt-6 space-x-4">
                <a href="edit.php?download=zip" class="px-6 py-2 bg-green-500 text-white font-semibold rounded hover:bg-green-600">Download ZIP</a>
                <a href="edit.php?download=csv" class="px-6 py-2 bg-blue-500 text-white font-semibold rounded hover:bg-blue-600">Download CSV</a>
                <a href="uploads.php" class="px-6 py-2 bg-blue-500 text-white font-semibold rounded hover:bg-blue-600">Back to Upload Page</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
