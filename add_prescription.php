<?php
session_start();
require 'db_connect.php';

// 1. Security Check (Using 'or' keyword to prevent any pipe symbol issues)
if (!isset($_SESSION['user_id']) or !in_array($_SESSION['role'] ?? '', ['Doctor', 'Admin'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];$role      = $_SESSION['role'] ?? '';$message   = "";
$message_type = "";

// Auto-Fix: prescriptions table check
try {
    $check_test_col =$pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'tests_prescribed'");
    if ($check_test_col && $check_test_col->rowCount() == 0) {$pdo->exec("ALTER TABLE prescriptions ADD tests_prescribed TEXT NULL AFTER medicines_json");
    }
} catch (PDOException $e) {}

// 🚀 [UPDATED PART] 2. Pharmacy Medicines List Load for Auto-suggest
$pharmacy_medicines_list = [];
try {
    $stmt_med =$pdo->query("SELECT medicine_name, stock_quantity FROM pharmacy_medicines WHERE stock_quantity > 0 ORDER BY medicine_name ASC");
    if ($stmt_med) {
        $pharmacy_medicines_list =$stmt_med->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// 3. Doctor ID Load
$current_doctor_id = null;
if ($role == 'Doctor') {
    $stmt_d =$pdo->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
    $stmt_d->execute([$user_id]);
    $current_doctor_id =$stmt_d->fetchColumn();
}

$preset_appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : null;
$preset_problem = "";

// 4. Load Pending Appointments
$appointments_list = [];
try {
    $sql_app = "SELECT a.appointment_id, a.patient_id, a.appointment_date, a.problem_description, p.full_name AS patient_name, p.dob 
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN prescriptions pr ON a.appointment_id = pr.appointment_id
                WHERE pr.prescription_id IS NULL ";

    if ($role == 'Doctor' and $current_doctor_id) {$sql_app .= " AND a.doctor_id = ? ORDER BY a.appointment_id DESC";
        $stmt_a = $pdo->prepare($sql_app);
        $stmt_a->execute([$current_doctor_id]);
    } else {
        $sql_app .= " ORDER BY a.appointment_id DESC";
        $stmt_a = $pdo->query($sql_app);
    }
    if ($stmt_a) {
        $appointments_list =$stmt_a->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {$message = "Error fetching appointments: " . $e->getMessage();$message_type = "danger";
}

if ($preset_appointment_id) {
    foreach ($appointments_list as$app_item) {
        if ($app_item['appointment_id'] ==$preset_appointment_id) {
            $preset_problem =$app_item['problem_description'] ?? '';
            break;
        }
    }
}

$tests_by_category = [
    'Pathology / Hematology' => [
        'Complete Blood Count (CBC)',
        'Hemoglobin (Hb%)',
        'ESR'
    ],
    'Biochemistry' => [
        'Random Blood Sugar (RBS)',
        'Fasting Blood Sugar (FBS)',
        'Serum Creatinine (Kidney Test)',
        'Lipid Profile',
        'Liver Function Test (LFT)'
    ],
    'Radiology & Imaging' => [
        'X-Ray Chest PA View',
        'USG of Whole Abdomen',
        'CT Scan of Brain',
        'MRI of Brain'
    ],
    'Cardiology & Others' => [
        'ECG (Electrocardiogram)',
        'Echocardiogram',
        'Urine R/E'
    ]
];

// 5. Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $diagnosis      = trim($_POST['diagnosis'] ?? '');
    $advice         = trim($_POST['advice'] ?? '');

    $med_names      =$_POST['med_name'] ?? [];
    $med_dosages    =$_POST['med_dosage'] ?? [];
    $med_timings    =$_POST['med_timing'] ?? [];
    $med_durations  =$_POST['med_duration'] ?? [];

    $selected_tests = isset($_POST['advised_tests']) ? implode(", ", $_POST['advised_tests']) : "";
    $other_tests    = trim($_POST['other_tests'] ?? '');
    if (!empty($other_tests)) {$selected_tests .= (!empty($selected_tests) ? ", " : "") . $other_tests;
    }

    $medicines_array = [];
    for ($i = 0; $i < count($med_names);$i++) {
        if (!empty(trim($med_names[$i]))) {$medicines_array[] = [
                'name'     => trim($med_names[$i]),
                'dosage'   => trim($med_dosages[$i] ?? '1+0+1'),
                'timing'   => trim($med_timings[$i] ?? 'After Food'),
                'duration' => trim($med_durations[$i] ?? '7 Days')
            ];
        }
    }

    if (empty($appointment_id)) {$message = "Please select a valid appointment!";
        $message_type = "warning";
    } elseif (empty($medicines_array)) {$message = "Please add at least one medicine!";
        $message_type = "warning";
    } else {
        try {
            $stmt_chk =$pdo->prepare("SELECT patient_id, doctor_id FROM appointments WHERE appointment_id = ?");
            $stmt_chk->execute([$appointment_id]);
            $app_info =$stmt_chk->fetch(PDO::FETCH_ASSOC);

            if (!$app_info) {$message = "Invalid Appointment selected!";
                $message_type = "danger";
            } else {
                $patient_id_to_save =$app_info['patient_id'];
                $doctor_id_to_save  =$app_info['doctor_id'];
                $med_json           = json_encode($medicines_array, JSON_UNESCAPED_UNICODE);

                $ins =$pdo->prepare("INSERT INTO prescriptions (appointment_id, patient_id, doctor_id, diagnosis, medicines_json, tests_prescribed, advice) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$appointment_id, $patient_id_to_save,$doctor_id_to_save, $diagnosis,$med_json, $selected_tests,$advice]);

                $inserted_id =$pdo->lastInsertId();

                $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE appointment_id = ?")->execute([$appointment_id]);

                header("Location: view_prescription.php?id=" . $inserted_id);
                exit();
            }
        } catch (PDOException $e) {$message = "Database Error: " . $e->getMessage();$message_type = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Write Prescription - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .prescription-card { max-width: 900px; margin: 30px auto; border: none; border-radius: 12px; }
        .card-header { background-color: #1e293b; color: #ffffff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .med-row { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
        .problem-info-box { background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 6px; padding: 12px 16px; margin-bottom: 15px; }
    </style>
</head>
<body>

<!-- DataList for Pharmacy Medicine Auto-suggest -->
<datalist id="pharmacyMedicinesList">
    <?php foreach ($pharmacy_medicines_list as$p_med): ?>
        <option value="<?= htmlspecialchars($p_med['medicine_name']); ?>">Available in Stock: <?= $p_med['stock_quantity']; ?> Pcs</option>
    <?php endforeach; ?>
</datalist>

<div class="container py-4">
    <div class="card prescription-card shadow">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-prescription2 me-2"></i>Create Patient Prescription (Rx)</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="add_prescription.php">
                
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Select Appointment <span class="text-danger">*</span></label>
                        <select name="appointment_id" id="appointmentSelect" class="form-select" onchange="updateProblemBox()" required>
                            <option value="" data-problem="">-- Choose Pending Appointment --</option>
                            <?php foreach ($appointments_list as$app): ?>
                                <?php 
                                    $patient_age = 'N/A';
                                    if (!empty($app['dob'])) {
                                        $dob_date = new DateTime($app['dob']);
                                        $today    = new DateTime();$calc_age = $today->diff($dob_date)->y;
                                        $patient_age = $calc_age > 0 ?$calc_age . " Years" : "1 Year";
                                    }
                                    $prob_text  = !empty($app['problem_description']) ? htmlspecialchars($app['problem_description']) : 'No problem description specified';$clean_date = !empty($app['appointment_date']) ? date('Y-m-d', strtotime($app['appointment_date'])) : 'N/A';
                                ?>
                                <option value="<?= $app['appointment_id']; ?>" 
                                        data-problem="<?= $prob_text; ?>"
                                        <?= ($preset_appointment_id ==$app['appointment_id']) ? 'selected' : ''; ?>>
                                    #APP-<?= $app['appointment_id']; ?>: <?= htmlspecialchars($app['patient_name']); ?> (Age: <?= $patient_age; ?> - Date: <?=$clean_date; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Prescription Date</label>
                        <input type="text" class="form-control bg-light" value="<?= date('d M, Y'); ?>" readonly>
                    </div>
                </div>

                <div id="problemDisplayContainer" class="problem-info-box" style="<?= empty($preset_problem) ? 'display: none;' : ''; ?>">
                    <strong class="text-primary"><i class="bi bi-chat-square-text me-1"></i> Patient Reported Problem:</strong> 
                    <span id="patientProblemText" class="text-dark fw-semibold"><?= htmlspecialchars($preset_problem); ?></span>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Diagnosis / Clinical Findings</label>
                    <textarea name="diagnosis" class="form-control" rows="3" placeholder="Enter patient diagnosis, symptoms, or disease details..."></textarea>
                </div>

                <hr class="my-4">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-primary mb-0"><i class="bi bi-capsule me-1"></i> Prescribed Medicines (Rx)</h6>
                    <button type="button" class="btn btn-sm btn-success" onclick="addMedicineRow()"><i class="bi bi-plus-lg me-1"></i> Add Medicine</button>
                </div>

                <div id="medicineContainer">
                    <div class="med-row">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <label class="small fw-semibold">Medicine Name & Type</label>
                                <input type="text" name="med_name[]" class="form-control" list="pharmacyMedicinesList" placeholder="e.g. Napa Extra 500mg" required>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-semibold">Dosage</label>
                                <select name="med_dosage[]" class="form-select">
                                    <option value="1+0+1">1+0+1 (সকাল+রাত)</option>
                                    <option value="1+1+1">1+1+1 (তিন বেলা)</option>
                                    <option value="0+0+1">0+0+1 (শুধু রাত)</option>
                                    <option value="1+0+0">1+0+0 (শুধু সকাল)</option>
                                    <option value="0+1+0">0+1+0 (শুধু দুপুর)</option>
                                    <option value="Need Base">প্রয়োজন হলে (SOS)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-semibold">Instruction</label>
                                <select name="med_timing[]" class="form-select">
                                    <option value="After Food">খাওয়ার পরে</option>
                                    <option value="Before Food">খাবারের আগে</option>
                                    <option value="With Food">খাবারের সাথে</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-semibold">Duration</label>
                                <input type="text" name="med_duration[]" class="form-control" value="7 Days">
                            </div>
                            <div class="col-md-1 text-end mt-4">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeMedicineRow(this)" title="Remove"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card my-4 border-primary shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-ui-checks me-1"></i> Advised Diagnostic Tests / Investigations</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($tests_by_category as $category =>$tests): ?>
                                <div class="col-md-3 mb-3">
                                    <h6 class="fw-bold text-dark border-bottom pb-1">🧪 <?= htmlspecialchars($category); ?></h6>
                                    <?php foreach ($tests as$test_name): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="advised_tests[]" value="<?= htmlspecialchars($test_name); ?>" id="test_<?= md5($test_name); ?>">
                                            <label class="form-check-label small" for="test_<?= md5($test_name); ?>"><?= htmlspecialchars($test_name); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-2">
                            <label class="form-label fw-semibold small">Other Custom Tests (If any):</label>
                            <input type="text" name="other_tests" class="form-control form-control-sm" placeholder="e.g. Endoscopy, CT Scan of Brain...">
                        </div>
                    </div>
                </div>

                <div class="mt-4 mb-4">
                    <label class="form-label fw-semibold">Doctor's Advice & Instructions</label>
                    <textarea name="advice" class="form-control" rows="3" placeholder="Drink plenty of warm water, complete course..."></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-printer me-1"></i> Save & View Prescription</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateProblemBox() {
    const select = document.getElementById('appointmentSelect');
    const selectedOption = select.options[select.selectedIndex];
    const problemText = selectedOption.getAttribute('data-problem');
    const container = document.getElementById('problemDisplayContainer');
    const textSpan = document.getElementById('patientProblemText');

    if (problemText && problemText.trim() !== "") {
        textSpan.textContent = problemText;
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
}

function addMedicineRow() {
    const container = document.getElementById('medicineContainer');
    const newRow = document.createElement('div');
    newRow.className = 'med-row';
    newRow.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="med_name[]" class="form-control" list="pharmacyMedicinesList" placeholder="Medicine Name" required>
            </div>
            <div class="col-md-2">
                <select name="med_dosage[]" class="form-select">
                    <option value="1+0+1">1+0+1 (সকাল+রাত)</option>
                    <option value="1+1+1">1+1+1 (তিন বেলা)</option>
                    <option value="0+0+1">0+0+1 (শুধু রাত)</option>
                    <option value="1+0+0">1+0+0 (শুধু সকাল)</option>
                    <option value="0+1+0">0+1+0 (শুধু দুপুর)</option>
                    <option value="Need Base">প্রয়োজন হলে (SOS)</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="med_timing[]" class="form-select">
                    <option value="After Food">খাওয়ার পরে</option>
                    <option value="Before Food">খাবারের আগে</option>
                    <option value="With Food">খাবারের সাথে</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" name="med_duration[]" class="form-control" value="7 Days">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeMedicineRow(this)"><i class="bi bi-trash"></i></button>
            </div>
        </div>
    `;
    container.appendChild(newRow);
}

function removeMedicineRow(button) {
    const rows = document.querySelectorAll('.med-row');
    if (rows.length > 1) {
        button.closest('.med-row').remove();
    } else {
        alert("At least one medicine is required in prescription!");
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>