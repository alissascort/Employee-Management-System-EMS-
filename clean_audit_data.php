<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Start transaction
    $conn->beginTransaction();
    
    // Count records before cleanup
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audits");
    $stmt->execute();
    $auditsBefore = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audit_findings");
    $stmt->execute();
    $findingsBefore = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Remove test/dummy audit records
    $stmt = $conn->prepare("
        DELETE FROM audit_findings 
        WHERE audit_id IN (
            SELECT id FROM audits 
            WHERE title LIKE '%Test%' 
            OR title LIKE '%dummy%' 
            OR title LIKE '%sample%'
            OR summary LIKE '%test%'
            OR summary LIKE '%dummy%'
            OR summary LIKE '%sample%'
        )
    ");
    $stmt->execute();
    $findingsDeleted = $stmt->rowCount();
    
    $stmt = $conn->prepare("
        DELETE FROM audits 
        WHERE title LIKE '%Test%' 
        OR title LIKE '%dummy%' 
        OR title LIKE '%sample%'
        OR summary LIKE '%test%'
        OR summary LIKE '%dummy%'
        OR summary LIKE '%sample%'
    ");
    $stmt->execute();
    $auditsDeleted = $stmt->rowCount();
    
    // Remove orphaned findings (findings without parent audits)
    $stmt = $conn->prepare("
        DELETE FROM audit_findings 
        WHERE audit_id NOT IN (SELECT id FROM audits)
    ");
    $stmt->execute();
    $orphanedDeleted = $stmt->rowCount();
    
    // Count records after cleanup
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audits");
    $stmt->execute();
    $auditsAfter = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audit_findings");
    $stmt->execute();
    $findingsAfter = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Dummy audit data cleaned successfully',
        'cleanup_summary' => [
            'audits_deleted' => $auditsDeleted,
            'findings_deleted' => $findingsDeleted,
            'orphaned_findings_deleted' => $orphanedDeleted,
            'total_cleanup' => $auditsDeleted + $findingsDeleted + $orphanedDeleted
        ],
        'before_after' => [
            'audits' => ['before' => $auditsBefore, 'after' => $auditsAfter],
            'findings' => ['before' => $findingsBefore, 'after' => $findingsAfter]
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error cleaning data: ' . $e->getMessage()
    ]);
}
?> 