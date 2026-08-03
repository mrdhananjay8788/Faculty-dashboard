<?php
$c = new mysqli('localhost', 'root', '', 'saaes_db');
$stmt = $c->prepare("SELECT a.activity_id AS id, a.subject AS subject_code, a.unit AS unit, a.title AS title, a.description AS description, a.due_date AS due_date, a.max_marks AS max_marks, s.id AS submission_id, s.original_filename, s.submission_date, s.status AS sub_status, s.marks, s.file_type, s.remarks FROM activities a LEFT JOIN submissions s ON s.activity_id = a.activity_id AND s.student_id = ? ORDER BY a.due_date ASC");
$id = 1;
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
echo 'rows=' . $result->num_rows . PHP_EOL;
$stmt->close();
$c->close();
