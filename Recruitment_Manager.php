<?php
class RecruitmentManager {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Get all recruitment-related data
    public function getRecruitmentData() {
        try {
            $data = [
                'job_postings' => $this->getJobPostings(),
                'applications' => $this->getApplications(),
                'candidates' => $this->getCandidates()
            ];
            return [
                'success' => true,
                'message' => 'Recruitment data loaded successfully',
                'data' => $data
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error loading recruitment data: ' . $e->getMessage()
            ];
        }
    }

    // Retrieve active job postings
    private function getJobPostings() {
        $query = "SELECT job_id, title, department, location, status, created_at, closing_date
                  FROM job_postings
                  ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Retrieve submitted job applications
    private function getApplications() {
        $query = "SELECT a.application_id, a.job_id, a.candidate_id, a.status, a.submitted_at,
                         j.title AS job_title, c.first_name, c.last_name, c.email
                  FROM job_applications a
                  JOIN job_postings j ON a.job_id = j.job_id
                  JOIN candidates c ON a.candidate_id = c.candidate_id
                  ORDER BY a.submitted_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Retrieve candidate details
    private function getCandidates() {
        $query = "SELECT candidate_id, first_name, last_name, email, phone, skills, resume, status, created_at
                  FROM candidates
                  ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
