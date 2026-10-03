<?php
// ==========================================
// 1. CONFIGURATION & DATABASE CONNECTION
// ==========================================
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', 'root', '', 'movie_watchlist');
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ==========================================
// 2. ROUTING & LOGIC
// ==========================================
$action = $_GET['action'] ?? 'read';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$error_msg = '';

// Get the current year dynamically so we don't have to update the code every January!
$current_year = (int)date('Y'); 

// --- HANDLE POST REQUESTS (Create & Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    $status = $_POST['status'] ?? 'To Watch';
    $rating = filter_var($_POST['rating'] ?? 0, FILTER_VALIDATE_INT);
    $notes = trim($_POST['notes'] ?? '');
    
    // Handle Year: Make it optional, but validate if provided
    $year_input = $_POST['release_year'] ?? '';
    $year = ($year_input === '') ? null : filter_var($year_input, FILTER_VALIDATE_INT);

    // LOGIC 1: The 1888 to Current Year Rule!
    if ($year !== null && ($year < 1888 || $year > $current_year)) {
        $error_msg = "Release year must be between 1888 and $current_year!";
    }

    // If no errors, proceed to database
    if (empty($error_msg) && !empty($title)) {
        try {
            if ($action === 'update' && $id) {
                $stmt = $conn->prepare("UPDATE movies SET title=?, genre=?, release_year=?, status=?, rating=?, notes=? WHERE id=?");
                $stmt->bind_param("ssisssi", $title, $genre, $year, $status, $rating, $notes, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO movies (title, genre, release_year, status, rating, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssisis", $title, $genre, $year, $status, $rating, $notes);
            }
            $stmt->execute();
            $stmt->close();
            header("Location: index.php?action=read"); 
            exit;
        } catch (mysqli_sql_exception $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    } elseif (empty($title)) {
        $error_msg = "Title is required!";
    }
}

// --- HANDLE GET REQUESTS (Delete) ---
if ($action === 'delete' && $id) {
    try {
        $stmt = $conn->prepare("DELETE FROM movies WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        $error_msg = "Error deleting movie.";
    }
    header("Location: index.php?action=read");
    exit;
}

// --- FETCH DATA FOR DISPLAY ---
$movie_to_edit = null;
if ($action === 'update' && $id) {
    $stmt = $conn->prepare("SELECT * FROM movies WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $movie_to_edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$movie_to_edit) die("Movie not found.");
}

$movies = [];
$filter_status = $_GET['filter'] ?? '';
if ($action === 'read') {
    $sql = "SELECT * FROM movies";
    if ($filter_status) {
        $sql .= " WHERE status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $filter_status);
        $stmt->execute();
        $movies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $movies = $conn->query($sql . " ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!-- ==========================================
     3. HTML & UI (Bootstrap 5 Powered)
     ========================================== -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Watchlist App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; color: #212529; }
        .app-container { max-width: 1100px; margin: 40px auto; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .app-header { border-bottom: 3px solid #6f42c1; padding-bottom: 15px; margin-bottom: 25px; }
        .app-header h2 { font-weight: 700; color: #212529; margin: 0; }
        .btn-primary { background-color: #6f42c1; border-color: #6f42c1; }
        .btn-primary:hover { background-color: #5a32a3; border-color: #5a32a3; }
        .table thead th { background-color: #6f42c1; color: white; border: none; font-weight: 500; }
        .notes-cell { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
        .notes-cell:hover { white-space: normal; overflow: visible; position: relative; z-index: 10; background: white; padding: 5px; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .filter-link { text-decoration: none; color: #6c757d; font-weight: 500; margin-right: 15px; transition: color 0.2s; }
        .filter-link:hover, .filter-link.active { color: #6f42c1; text-decoration: underline; }
    </style>
</head>
<body>

<div class="container app-container">
    
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($action === 'read'): ?>
        <!-- ================= READ PAGE (MAIN LIST) ================= -->
        <div class="card p-4">
            <div class="app-header d-flex justify-content-between align-items-center">
                <h2>🎬 My Movie Watchlist</h2>
                <a href="index.php?action=create" class="btn btn-primary">+ Add New Movie</a>
            </div>
            
            <div class="mb-4">
                <strong class="me-2">Filter by Status:</strong>
                <a href="index.php" class="filter-link <?= !$filter_status ? 'active' : '' ?>">All</a>
                <a href="index.php?filter=To Watch" class="filter-link <?= $filter_status == 'To Watch' ? 'active' : '' ?>">To Watch</a>
                <a href="index.php?filter=Watching" class="filter-link <?= $filter_status == 'Watching' ? 'active' : '' ?>">Watching</a>
                <a href="index.php?filter=Watched" class="filter-link <?= $filter_status == 'Watched' ? 'active' : '' ?>">Watched</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Genre</th>
                            <th>Year</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($movies)): ?>
                            <?php foreach ($movies as $movie): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($movie['title']) ?></td>
                                <td><?= htmlspecialchars($movie['genre']) ?></td>
                                <td><?= htmlspecialchars($movie['release_year'] ?? 'Unknown') ?></td>
                                <td>
                                    <?php if ($movie['status'] === 'Watched'): ?>
                                        <?php 
                                        $r = $movie['rating'];
                                        if ($r !== 0 && $r !== null) {
                                            $color = $r > 0 ? 'warning' : 'danger'; 
                                            echo '<span class="badge bg-' . $color . ' text-dark">★ ' . number_format($r) . '</span>';
                                        } else {
                                            echo '<span class="text-muted">Unrated</span>';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $status_color = $movie['status'] == 'Watched' ? 'success' : ($movie['status'] == 'Watching' ? 'info' : 'secondary'); ?>
                                    <span class="badge bg-<?= $status_color ?>"><?= htmlspecialchars($movie['status']) ?></span>
                                </td>
                                <td>
                                    <span class="notes-cell" title="<?= htmlspecialchars($movie['notes']) ?>">
                                        <?= htmlspecialchars($movie['notes']) ?: 'No notes' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="index.php?action=update&id=<?= $movie['id'] ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                                    <a href="index.php?action=delete&id=<?= $movie['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this movie?')">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No movies found. Start adding some!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- ================= CREATE / UPDATE PAGE (FORM) ================= -->
        <div class="card p-4">
            <div class="app-header">
                <h2><?= $action === 'update' ? '✏️ Edit Movie' : '➕ Add New Movie' ?></h2>
            </div>
            
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" value="<?= htmlspecialchars($movie_to_edit['title'] ?? '') ?>" placeholder="e.g. Inception" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Genre</label>
                        <input type="text" class="form-control" name="genre" value="<?= htmlspecialchars($movie_to_edit['genre'] ?? '') ?>" placeholder="e.g. Sci-Fi">
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Release Year (Optional)</label>
                        <!-- LOGIC 1: min 1888, max is dynamically set to current year via PHP -->
                        <input type="number" class="form-control" name="release_year" min="1888" max="<?= $current_year ?>" value="<?= htmlspecialchars($movie_to_edit['release_year'] ?? '') ?>" placeholder="1888 - <?= $current_year ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Status</label>
                        <select class="form-select" name="status" id="statusSelect" onchange="toggleRatingField()">
                            <?php 
                            $current_status = $movie_to_edit['status'] ?? 'To Watch';
                            foreach (['To Watch', 'Watching', 'Watched'] as $s): 
                            ?>
                                <option value="<?= $s ?>" <?= $current_status == $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- LOGIC 3: Rating Box -->
                    <div class="col-md-4" id="ratingBox" style="display: none;">
                        <label class="form-label fw-bold">Rating (Be honest!)</label>
                        <input type="number" class="form-control" name="rating" value="<?= htmlspecialchars($movie_to_edit['rating'] ?? 0) ?>" placeholder="e.g. 10, 1000000, or -9999">
                        <div class="form-text">Only rate if you've watched it! No limits.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Personal Notes</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Why watch this? Or your review!"><?= htmlspecialchars($movie_to_edit['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4"><?= $action === 'update' ? 'Update Movie' : 'Save Movie' ?></button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function toggleRatingField() {
        const status = document.getElementById('statusSelect').value;
        const ratingBox = document.getElementById('ratingBox');
        if (status === 'Watched') {
            ratingBox.style.display = 'block';
        } else {
            ratingBox.style.display = 'none';
            document.querySelector('input[name="rating"]').value = 0;
        }
    }
    toggleRatingField();
</script>

</body>
</html>