-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql102.infinityfree.com
-- Generation Time: Sep 20, 2026 at 11:04 PM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_42914892_tareydermaclinic_new`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounting`
--

CREATE TABLE `accounting` (
  `EntryID` varchar(50) NOT NULL,
  `AccountID` varchar(50) NOT NULL,
  `AccountName` varchar(150) NOT NULL,
  `AccountType` enum('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
  `BookType` enum('General Journal','Cash Book','Sales Book','Purchases Book') NOT NULL,
  `TransactionDate` datetime DEFAULT current_timestamp(),
  `ReferenceID` varchar(50) DEFAULT NULL,
  `Description` text NOT NULL,
  `Debit` decimal(12,2) DEFAULT 0.00,
  `Credit` decimal(12,2) DEFAULT 0.00,
  `Balance` decimal(12,2) DEFAULT 0.00,
  `CreatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounting`
--

INSERT INTO `accounting` (`EntryID`, `AccountID`, `AccountName`, `AccountType`, `BookType`, `TransactionDate`, `ReferenceID`, `Description`, `Debit`, `Credit`, `Balance`, `CreatedAt`) VALUES
('JRN000001', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-17 17:23:42', 'POS000001', 'Point of sale collection POS000001', '0.00', '58.00', '58.00', '2026-09-17 17:23:42'),
('JRN000002', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-18 10:53:22', 'POS000001-VOID', 'Reversal of POS000001', '58.00', '0.00', '58.00', '2026-09-18 10:53:22'),
('JRN000003', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 11:16:31', 'PAY000001', 'Consultation payment for VIS000002', '5.00', '0.00', '5.00', '2026-09-18 11:16:31'),
('JRN000004', 'REV-CONSULT', 'Consultation Revenue', 'Revenue', 'Sales Book', '2026-09-18 11:16:31', 'PAY000001', 'Consultation payment for VIS000002', '0.00', '5.00', '5.00', '2026-09-18 11:16:31'),
('JRN000005', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 11:17:28', 'PAY000002', 'Consultation payment for VIS000004', '5.00', '0.00', '5.00', '2026-09-18 11:17:28'),
('JRN000006', 'REV-CONSULT', 'Consultation Revenue', 'Revenue', 'Sales Book', '2026-09-18 11:17:28', 'PAY000002', 'Consultation payment for VIS000004', '0.00', '5.00', '5.00', '2026-09-18 11:17:28'),
('JRN000007', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 11:17:49', 'PAY000003', 'Consultation payment for VIS000002', '0.50', '0.00', '0.50', '2026-09-18 11:17:49'),
('JRN000008', 'REV-CONSULT', 'Consultation Revenue', 'Revenue', 'Sales Book', '2026-09-18 11:17:49', 'PAY000003', 'Consultation payment for VIS000002', '0.00', '0.50', '0.50', '2026-09-18 11:17:49'),
('JRN000009', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 11:50:10', 'PAY000004', 'Pharmacy payment for RX000001', '58.00', '0.00', '58.00', '2026-09-18 11:50:10'),
('JRN000010', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-18 11:50:10', 'PAY000004', 'Pharmacy payment for RX000001', '0.00', '58.00', '58.00', '2026-09-18 11:50:10'),
('JRN000011', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 12:50:44', 'PAY000005', 'Pharmacy payment for RX000002', '116.00', '0.00', '116.00', '2026-09-18 12:50:44'),
('JRN000012', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-18 12:50:44', 'PAY000005', 'Pharmacy payment for RX000002', '0.00', '116.00', '116.00', '2026-09-18 12:50:44'),
('JRN000013', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-18 22:13:48', 'POS000004', 'Point of sale collection POS000004', '7.00', '0.00', '7.00', '2026-09-18 22:13:48'),
('JRN000014', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-18 22:13:48', 'POS000004', 'Point of sale collection POS000004', '0.00', '7.00', '7.00', '2026-09-18 22:13:48'),
('JRN000015', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-19 06:57:37', 'PAY000007', 'Consultation payment for VIS000007', '5.50', '0.00', '5.50', '2026-09-19 06:57:37'),
('JRN000016', 'REV-CONSULT', 'Consultation Revenue', 'Revenue', 'Sales Book', '2026-09-19 06:57:37', 'PAY000007', 'Consultation payment for VIS000007', '0.00', '5.50', '5.50', '2026-09-19 06:57:37'),
('JRN000017', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-19 07:04:40', 'PAY000008', 'Pharmacy payment for RX000003', '8.00', '0.00', '8.00', '2026-09-19 07:04:40'),
('JRN000018', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-19 07:04:40', 'PAY000008', 'Pharmacy payment for RX000003', '0.00', '8.00', '8.00', '2026-09-19 07:04:40'),
('JRN000019', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-19 07:20:46', 'PAY000009', 'Consultation payment for VIS000004', '0.50', '0.00', '0.50', '2026-09-19 07:20:46'),
('JRN000020', 'REV-CONSULT', 'Consultation Revenue', 'Revenue', 'Sales Book', '2026-09-19 07:20:46', 'PAY000009', 'Consultation payment for VIS000004', '0.00', '0.50', '0.50', '2026-09-19 07:20:46'),
('JRN000021', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-19 07:51:18', 'PAY000010', 'Laboratory payment for LAB000001', '1.00', '0.00', '1.00', '2026-09-19 07:51:18'),
('JRN000022', 'REV-LAB', 'Laboratory Revenue', 'Revenue', 'Sales Book', '2026-09-19 07:51:18', 'PAY000010', 'Laboratory payment for LAB000001', '0.00', '1.00', '1.00', '2026-09-19 07:51:18'),
('JRN000023', 'PAY-EVC_PLUS', 'EVC Plus Clearing', 'Asset', 'Sales Book', '2026-09-20 06:30:03', 'PAY000011', 'Pharmacy payment for RX000005', '6.00', '0.00', '6.00', '2026-09-20 06:30:03'),
('JRN000024', 'REV-PHARM', 'Pharmacy Revenue', 'Revenue', 'Sales Book', '2026-09-20 06:30:03', 'PAY000011', 'Pharmacy payment for RX000005', '0.00', '6.00', '6.00', '2026-09-20 06:30:03');

-- --------------------------------------------------------

--
-- Table structure for table `auditlog`
--

CREATE TABLE `auditlog` (
  `AuditID` bigint(20) NOT NULL,
  `ActorUserID` int(11) DEFAULT NULL,
  `EventType` varchar(100) NOT NULL,
  `EntityType` varchar(100) NOT NULL,
  `EntityID` varchar(100) DEFAULT NULL,
  `Summary` varchar(500) NOT NULL,
  `ChangesJson` longtext DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auditlog`
--

INSERT INTO `auditlog` (`AuditID`, `ActorUserID`, `EventType`, `EntityType`, `EntityID`, `Summary`, `ChangesJson`, `CreatedAt`) VALUES
(1, 8, 'setup.user.saved', 'users', '6', 'Saved user account: tarey', NULL, '2026-09-16 07:08:49'),
(2, 8, 'setup.permissions.updated', 'Roles', '2', 'Changed permissions for Reception', '{\"added\":[\"doctor.workspace\",\"doctors.export\",\"doctors.import\",\"doctors.manage\",\"doctors.view\"],\"removed\":[]}', '2026-09-16 07:26:24'),
(3, 8, 'setup.user.saved', 'users', '7', 'Saved user account: Tareey', NULL, '2026-09-16 08:06:55'),
(4, 8, 'setup.user.saved', 'users', '7', 'Saved user account: Tareey', NULL, '2026-09-16 08:07:21'),
(5, 8, 'patients.imported', 'Patients', NULL, 'CSV import: 3 imported, 1 skipped, 0 failed of 4 rows.', NULL, '2026-09-18 09:47:02'),
(6, 8, 'patients.imported', 'Patients', NULL, 'CSV import: 3 imported, 1 skipped, 0 failed of 4 rows.', NULL, '2026-09-18 09:47:30'),
(7, 8, 'patients.imported', 'Patients', NULL, 'CSV import: 3 imported, 0 skipped, 0 failed of 3 rows.', NULL, '2026-09-18 10:08:17'),
(8, 8, 'payment.recorded', 'payment', 'PAY000004', 'Pharmacy payment of 58.00 recorded against bill RX000001 via EVC Plus', '{\"bill\":\"RX000001\",\"amount\":58,\"method\":\"EVC Plus\"}', '2026-09-18 11:50:10'),
(9, 8, 'payment.recorded', 'payment', 'PAY000005', 'Pharmacy payment of 116.00 recorded against bill RX000002 via EVC Plus', '{\"bill\":\"RX000002\",\"amount\":116,\"method\":\"EVC Plus\"}', '2026-09-18 12:50:44'),
(10, 8, 'prescription.dispensed', 'prescriptions', 'RX000002', 'Prescription dispensed after confirmed full payment.', '{\"paid\":116,\"total\":116}', '2026-09-18 12:50:56'),
(11, 8, 'setup.permissions.updated', 'Roles', '5', 'Changed permissions for Pharmacy', '{\"added\":[\"pharmacy.prescription.create\",\"pharmacy.purchase_cost.view\",\"pharmacy_billing.payment\",\"pharmacy_billing.view\"],\"removed\":[]}', '2026-09-18 22:11:15'),
(12, 8, 'setup.permissions.updated', 'Roles', '3', 'Changed permissions for Doctor', '{\"added\":[\"accounting.expenses.create\",\"accounting.expenses.edit\",\"accounting.journal.post\",\"accounting.journal.reverse\",\"accounting.transactions.view\",\"accounting.view\",\"doctors.export\",\"doctors.import\",\"doctors.manage\",\"laboratory.complete\",\"laboratory.process\",\"laboratory.result.create\",\"laboratory.result.edit\",\"laboratory.view\",\"patients.create\",\"patients.delete\",\"patients.edit\",\"patients.export\",\"patients.import\",\"patients.view\",\"pharmacy.dispense\",\"pharmacy.pos\",\"pharmacy.prescriptions.view\",\"pharmacy.purchases.manage\",\"pharmacy.purchase_cost.view\",\"pharmacy.view\",\"pharmacy_billing.payment\",\"pharmacy_billing.view\",\"reception.view\",\"reports.export\",\"reports.income.cost.view\",\"reports.view\",\"setup.clinical.manage\",\"setup.communication.manage\",\"setup.financial.manage\",\"setup.laboratory.manage\",\"setup.organization.manage\",\"setup.permissions.manage\",\"setup.pharmacy.manage\",\"setup.roles.manage\",\"setup.system.manage\",\"setup.users.manage\",\"setup.view\",\"visits.assign\",\"visits.create\",\"visits.edit\",\"visits.view\"],\"removed\":[]}', '2026-09-18 22:23:49'),
(13, 8, 'payment.recorded', 'payment', 'PAY000008', 'Pharmacy payment of 8.00 recorded against bill RX000003 via EVC Plus', '{\"bill\":\"RX000003\",\"amount\":8,\"method\":\"EVC Plus\"}', '2026-09-19 07:04:40'),
(14, 8, 'prescription.dispensed', 'prescriptions', 'RX000003', 'Prescription dispensed after confirmed full payment.', '{\"paid\":8,\"total\":8}', '2026-09-19 07:07:00'),
(15, 8, 'setup.lab_service.saved', 'LabServices', '1', 'Saved laboratory service: malaria', NULL, '2026-09-19 07:47:22'),
(16, 8, 'setup.lab_service.saved', 'LabServices', '2', 'Saved laboratory service: widal test', NULL, '2026-09-19 07:49:11'),
(17, 8, 'payment.recorded', 'payment', 'PAY000011', 'Pharmacy payment of 6.00 recorded against bill RX000005 via EVC Plus', '{\"bill\":\"RX000005\",\"amount\":6,\"method\":\"EVC Plus\"}', '2026-09-20 06:30:03'),
(18, 8, 'prescription.dispensed', 'prescriptions', 'RX000005', 'Prescription dispensed after confirmed full payment.', '{\"paid\":6,\"total\":6}', '2026-09-20 06:30:12');

-- --------------------------------------------------------

--
-- Table structure for table `clinicsettings`
--

CREATE TABLE `clinicsettings` (
  `SettingKey` varchar(100) NOT NULL,
  `SettingValue` text DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `DepartmentID` int(11) NOT NULL,
  `DepartmentName` varchar(120) NOT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `DoctorID` int(11) NOT NULL,
  `UserID` int(11) DEFAULT NULL,
  `DoctorName` varchar(150) NOT NULL,
  `ConsultationFee` decimal(10,2) DEFAULT 0.00,
  `Specialty` varchar(100) DEFAULT NULL,
  `JoinedDate` date DEFAULT NULL,
  `WorkingDays` varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
  `WorkStartTime` time NOT NULL DEFAULT '09:00:00',
  `WorkEndTime` time NOT NULL DEFAULT '17:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`DoctorID`, `UserID`, `DoctorName`, `ConsultationFee`, `Specialty`, `JoinedDate`, `WorkingDays`, `WorkStartTime`, `WorkEndTime`) VALUES
(1, NULL, 'Mohamed Ali Abid', '5.00', 'Dermatology', '2026-08-30', '1,2,3,4,5', '09:00:00', '17:00:00'),
(2, NULL, 'Dr Abdalla Osman', '8.00', 'Derma', '2026-08-30', '1,2,3,4,5', '09:00:00', '17:00:00'),
(3, NULL, 'Dr Zakaria Ali', '100.00', 'Dermatology', '2026-08-30', '1,2,3,4,5', '09:00:00', '17:00:00'),
(4, 7, 'dr tarey', '5.50', 'dermatologist', '2026-08-01', '1,2,3,4,5', '09:00:00', '17:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `ItemID` varchar(50) NOT NULL,
  `Category` varchar(100) DEFAULT NULL,
  `ItemName` varchar(150) NOT NULL,
  `ItemImage` text DEFAULT NULL,
  `QuantityInStock` int(11) NOT NULL DEFAULT 0,
  `SalesUnit` varchar(50) DEFAULT NULL,
  `SellingPrice` decimal(10,2) DEFAULT 0.00,
  `ReorderLevel` int(11) DEFAULT 10,
  `ExpiryDate` date DEFAULT NULL,
  `SupplierID` int(11) DEFAULT NULL,
  `LastUpdated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `DefaultPurchaseUnit` varchar(50) DEFAULT NULL,
  `UnitsPerPackage` int(11) DEFAULT NULL,
  `LastAcquisitionCostPerUnit` decimal(10,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`ItemID`, `Category`, `ItemName`, `ItemImage`, `QuantityInStock`, `SalesUnit`, `SellingPrice`, `ReorderLevel`, `ExpiryDate`, `SupplierID`, `LastUpdated`, `DefaultPurchaseUnit`, `UnitsPerPackage`, `LastAcquisitionCostPerUnit`) VALUES
('ITM000001', 'Px', 'Paractamol', NULL, 22, 'Box', '58.00', 10, '2026-09-18', NULL, '2026-09-18 12:50:56', NULL, NULL, NULL),
('ITM000002', 'anti fungal', 'itra cap', NULL, 7, 'Capsule', '3.00', 0, '2028-03-01', NULL, '2026-09-20 06:30:12', NULL, NULL, NULL),
('ITM000003', 'fungal', 'fintrix cream', NULL, 7, 'Other', '3.00', 0, '2028-01-01', NULL, '2026-09-20 06:30:12', NULL, NULL, NULL),
('ITM000004', 'fungal', 'novale soap', NULL, 3, 'Piece', '2.00', 0, '2028-01-01', NULL, '2026-09-19 07:07:00', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `laboratory`
--

CREATE TABLE `laboratory` (
  `LaboratoryID` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `VisitID` int(11) DEFAULT NULL,
  `DoctorID` int(11) DEFAULT NULL,
  `RequestedByUserID` int(11) DEFAULT NULL,
  `ServiceID` int(11) DEFAULT NULL,
  `TestID` int(11) NOT NULL,
  `TestName` varchar(150) NOT NULL,
  `Description` text DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PaymentStatus` enum('Paid','Unpaid','Partial') DEFAULT 'Unpaid',
  `WorkflowStatus` enum('Requested','Awaiting Payment','Ready','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Awaiting Payment',
  `IsAvailable` tinyint(1) DEFAULT 1,
  `OrderDate` datetime DEFAULT current_timestamp(),
  `Result` enum('Positive','Negative','Pending') DEFAULT 'Pending',
  `ClinicalResult` text DEFAULT NULL,
  `ResultDate` datetime DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `laboratory`
--

INSERT INTO `laboratory` (`LaboratoryID`, `PatientID`, `VisitID`, `DoctorID`, `RequestedByUserID`, `ServiceID`, `TestID`, `TestName`, `Description`, `TotalAmount`, `AmountPaid`, `DueBalance`, `PaymentStatus`, `WorkflowStatus`, `IsAvailable`, `OrderDate`, `Result`, `ClinicalResult`, `ResultDate`, `ReviewedAt`) VALUES
('LAB000001', 6, 4, 4, 8, 1, 1, 'malaria', '', '1.00', '1.00', '0.00', 'Paid', 'Completed', 1, '2026-09-19 07:51:02', 'Negative', NULL, '2026-09-19 10:51:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `laborderitems`
--

CREATE TABLE `laborderitems` (
  `LabOrderItemID` bigint(20) NOT NULL,
  `LaboratoryID` varchar(50) NOT NULL,
  `ServiceID` int(11) NOT NULL,
  `TestName` varchar(150) NOT NULL,
  `UnitPrice` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Result` enum('Positive','Negative','Pending') NOT NULL DEFAULT 'Pending',
  `ClinicalResult` text DEFAULT NULL,
  `ResultDate` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `laborderitems`
--

INSERT INTO `laborderitems` (`LabOrderItemID`, `LaboratoryID`, `ServiceID`, `TestName`, `UnitPrice`, `Result`, `ClinicalResult`, `ResultDate`) VALUES
(1, 'LAB000001', 1, 'malaria', '1.00', 'Negative', NULL, '2026-09-19 10:51:55');

-- --------------------------------------------------------

--
-- Table structure for table `labservices`
--

CREATE TABLE `labservices` (
  `ServiceID` int(11) NOT NULL,
  `ServiceName` varchar(150) NOT NULL,
  `Category` varchar(100) DEFAULT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `Price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `IsAvailable` tinyint(1) NOT NULL DEFAULT 1,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `labservices`
--

INSERT INTO `labservices` (`ServiceID`, `ServiceName`, `Category`, `Description`, `Price`, `IsAvailable`, `IsActive`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'malaria', 'hematology', '.', '1.00', 1, 1, '2026-09-19 07:47:22', '2026-09-19 07:47:22'),
(2, 'widal test', 'serology', '.', '2.00', 1, 1, '2026-09-19 07:49:11', '2026-09-19 07:49:11');

-- --------------------------------------------------------

--
-- Table structure for table `lab_categories`
--

CREATE TABLE `lab_categories` (
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(120) NOT NULL,
  `Description` text DEFAULT NULL,
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lab_categories`
--

INSERT INTO `lab_categories` (`CategoryID`, `CategoryName`, `Description`, `DisplayOrder`, `IsActive`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'Hematology', 'Blood and blood-cell analyses', 1, 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(2, 'Biochemistry', 'Chemical and metabolic analyses', 2, 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(3, 'Microbiology', 'Culture and infectious disease tests', 3, 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(4, 'Urinalysis', 'Urine and urinary screening tests', 4, 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(5, 'Serology', 'Immune and antibody screening', 5, 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `lab_centers`
--

CREATE TABLE `lab_centers` (
  `LabCenterID` int(11) NOT NULL,
  `CenterName` varchar(150) NOT NULL,
  `Location` varchar(200) DEFAULT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_center_tests`
--

CREATE TABLE `lab_center_tests` (
  `CenterTestID` int(11) NOT NULL,
  `LabCenterID` int(11) NOT NULL,
  `TestID` int(11) NOT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_flags`
--

CREATE TABLE `lab_flags` (
  `FlagID` int(11) NOT NULL,
  `FlagName` varchar(60) NOT NULL,
  `FlagCode` varchar(20) NOT NULL,
  `Description` varchar(200) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lab_flags`
--

INSERT INTO `lab_flags` (`FlagID`, `FlagName`, `FlagCode`, `Description`, `IsActive`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'Normal', 'N', 'Within expected range', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(2, 'Low', 'L', 'Below expected reference', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(3, 'High', 'H', 'Above expected reference', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(4, 'Abnormal', 'A', 'Outside expected range', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(5, 'Critical', 'C', 'Urgent clinical concern', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(6, 'Positive', 'POS', 'Positive laboratory finding', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(7, 'Negative', 'NEG', 'Negative laboratory finding', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `lab_order_catalog_bridge`
--

CREATE TABLE `lab_order_catalog_bridge` (
  `BridgeID` bigint(20) NOT NULL,
  `LaboratoryID` varchar(50) NOT NULL,
  `ModernTestID` int(11) DEFAULT NULL,
  `LegacyServiceID` int(11) DEFAULT NULL,
  `LegacyTestID` int(11) DEFAULT NULL,
  `LegacyTestNameSnapshot` varchar(150) DEFAULT NULL,
  `ModernTestNameSnapshot` varchar(180) DEFAULT NULL,
  `PriceSnapshot` decimal(10,2) NOT NULL DEFAULT 0.00,
  `SourceType` enum('legacy','modern','mixed') NOT NULL DEFAULT 'modern',
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_parameters`
--

CREATE TABLE `lab_parameters` (
  `ParameterID` int(11) NOT NULL,
  `TestID` int(11) NOT NULL,
  `ParameterName` varchar(150) NOT NULL,
  `ResultType` enum('Numeric','Text','Positive/Negative','Select') NOT NULL DEFAULT 'Numeric',
  `UnitID` int(11) DEFAULT NULL,
  `ReferenceRange` varchar(150) DEFAULT NULL,
  `NormalMinimum` decimal(18,6) DEFAULT NULL,
  `NormalMaximum` decimal(18,6) DEFAULT NULL,
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `IsRequired` tinyint(1) NOT NULL DEFAULT 1,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `SelectChoices` text DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_results`
--

CREATE TABLE `lab_results` (
  `LabResultID` bigint(20) NOT NULL,
  `BridgeID` bigint(20) NOT NULL,
  `LabCenterID` int(11) DEFAULT NULL,
  `ResultStatus` enum('Draft','Processing','Completed','Reviewed','Cancelled') NOT NULL DEFAULT 'Draft',
  `CollectedBy` int(11) DEFAULT NULL,
  `CollectedAt` datetime DEFAULT NULL,
  `CompletedBy` int(11) DEFAULT NULL,
  `CompletedAt` datetime DEFAULT NULL,
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_result_attachments`
--

CREATE TABLE `lab_result_attachments` (
  `AttachmentID` bigint(20) NOT NULL,
  `LabResultID` bigint(20) NOT NULL,
  `OriginalFileName` varchar(255) NOT NULL,
  `StoredFileName` varchar(255) NOT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` int(11) NOT NULL DEFAULT 0,
  `UploadedBy` int(11) DEFAULT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_result_parameters`
--

CREATE TABLE `lab_result_parameters` (
  `LabResultParameterID` bigint(20) NOT NULL,
  `LabResultID` bigint(20) NOT NULL,
  `ParameterID` int(11) DEFAULT NULL,
  `ParameterNameSnapshot` varchar(180) NOT NULL,
  `ResultTypeSnapshot` varchar(50) DEFAULT NULL,
  `UnitID` int(11) DEFAULT NULL,
  `UnitNameSnapshot` varchar(100) DEFAULT NULL,
  `ReferenceRangeSnapshot` varchar(255) DEFAULT NULL,
  `NormalMinimumSnapshot` decimal(18,6) DEFAULT NULL,
  `NormalMaximumSnapshot` decimal(18,6) DEFAULT NULL,
  `RawResult` text DEFAULT NULL,
  `DisplayResult` text DEFAULT NULL,
  `FlagID` int(11) DEFAULT NULL,
  `FlagCodeSnapshot` varchar(30) DEFAULT NULL,
  `FlagNameSnapshot` varchar(100) DEFAULT NULL,
  `Remark` varchar(500) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_result_review`
--

CREATE TABLE `lab_result_review` (
  `ReviewID` bigint(20) NOT NULL,
  `LabResultID` bigint(20) NOT NULL,
  `Action` varchar(80) NOT NULL,
  `Notes` text DEFAULT NULL,
  `PerformedBy` int(11) DEFAULT NULL,
  `PerformedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_tests`
--

CREATE TABLE `lab_tests` (
  `TestID` int(11) NOT NULL,
  `TypeID` int(11) NOT NULL,
  `TestName` varchar(180) NOT NULL,
  `Description` text DEFAULT NULL,
  `Price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ResultMode` enum('Structured Parameters','Single Result') NOT NULL DEFAULT 'Structured Parameters',
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_test_selection`
--

CREATE TABLE `lab_test_selection` (
  `SelectionID` int(11) NOT NULL,
  `TestID` int(11) NOT NULL,
  `DoctorID` int(11) NOT NULL,
  `LabCenterID` int(11) NOT NULL,
  `IsEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `Notes` text DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_types`
--

CREATE TABLE `lab_types` (
  `TypeID` int(11) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `TypeName` varchar(150) NOT NULL,
  `Description` text DEFAULT NULL,
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_units`
--

CREATE TABLE `lab_units` (
  `UnitID` int(11) NOT NULL,
  `UnitName` varchar(80) NOT NULL,
  `UnitSymbol` varchar(40) NOT NULL,
  `Description` text DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lab_units`
--

INSERT INTO `lab_units` (`UnitID`, `UnitName`, `UnitSymbol`, `Description`, `IsActive`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'Grams per deciliter', 'g/dL', 'Standard blood chemistry unit', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(2, 'Milligrams per deciliter', 'mg/dL', 'Standard chemistry value', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(3, 'Millimoles per litre', 'mmol/L', 'Biochemistry unit', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(4, 'Percent', '%', 'Percentage', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(5, 'International units per litre', 'IU/L', 'Clinical laboratory unit', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08'),
(6, '10^9 per litre', '10^9/L', 'Cell concentration', 1, '2026-09-19 12:09:08', '2026-09-19 12:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `AttemptKey` varchar(191) NOT NULL,
  `FailedCount` int(11) NOT NULL DEFAULT 0,
  `FirstAttempt` datetime NOT NULL,
  `BlockedUntil` datetime DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `NotificationID` bigint(20) NOT NULL,
  `UserID` int(11) DEFAULT NULL,
  `RoleTarget` enum('superuser','receptionuser','doctoruser','pharmacyuser','labuser') DEFAULT NULL,
  `EventType` varchar(50) NOT NULL,
  `Title` varchar(150) NOT NULL,
  `Message` varchar(500) NOT NULL,
  `Link` varchar(255) DEFAULT NULL,
  `IsRead` tinyint(1) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`NotificationID`, `UserID`, `RoleTarget`, `EventType`, `Title`, `Message`, `Link`, `IsRead`, `CreatedAt`) VALUES
(1, 7, NULL, 'consultation_ready', 'Consultation ready', 'VIS000002 is fully paid and waiting', 'doctors.php?visit=2', 0, '2026-09-18 11:17:49'),
(2, 5, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000001 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 11:20:23'),
(3, 8, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000001 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 11:20:23'),
(4, 6, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000001 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 11:20:23'),
(5, 7, NULL, 'prescription_dispensed', 'Prescription dispensed', 'RX000001 was dispensed as POS000002', 'doctors.php?visit=2', 0, '2026-09-18 11:20:58'),
(6, 5, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000002 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 12:49:28'),
(7, 8, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000002 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 12:49:28'),
(8, 6, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000002 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 12:49:28'),
(9, 7, NULL, 'prescription_dispensed', 'Prescription dispensed', 'RX000002 was dispensed.', 'doctors.php?visit=2', 0, '2026-09-18 12:50:56'),
(10, 5, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000003 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 21:55:01'),
(11, 8, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000003 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 21:55:01'),
(12, 6, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000003 for mohamed abdi', 'pharmacy.php?section=prescriptions', 0, '2026-09-18 21:55:01'),
(13, 7, NULL, 'consultation_booked', 'New consultation booked', 'VIS000007 is fully paid and waiting', 'doctors.php?visit=4', 0, '2026-09-19 06:57:37'),
(14, 7, NULL, 'prescription_dispensed', 'Prescription dispensed', 'RX000003 was dispensed.', 'doctors.php?visit=2', 0, '2026-09-19 07:07:00'),
(15, 7, NULL, 'consultation_ready', 'Consultation ready', 'VIS000004 is fully paid and waiting', 'doctors.php?visit=3', 0, '2026-09-19 07:20:46'),
(16, 5, NULL, 'lab_payment_due', 'Laboratory payment required', 'LAB000001 for axmed abdi nasir', 'reception.php?section=laboratory', 0, '2026-09-19 07:51:02'),
(17, 8, NULL, 'lab_payment_due', 'Laboratory payment required', 'LAB000001 for axmed abdi nasir', 'reception.php?section=laboratory', 0, '2026-09-19 07:51:02'),
(18, 6, NULL, 'lab_payment_due', 'Laboratory payment required', 'LAB000001 for axmed abdi nasir', 'reception.php?section=laboratory', 0, '2026-09-19 07:51:02'),
(19, 5, NULL, 'lab_ready', 'Paid laboratory request ready', 'LAB000001 is cleared for processing', 'laboratory.php?result=Pending&payment=Paid', 0, '2026-09-19 07:51:18'),
(20, 8, NULL, 'lab_ready', 'Paid laboratory request ready', 'LAB000001 is cleared for processing', 'laboratory.php?result=Pending&payment=Paid', 0, '2026-09-19 07:51:18'),
(21, 6, NULL, 'lab_ready', 'Paid laboratory request ready', 'LAB000001 is cleared for processing', 'laboratory.php?result=Pending&payment=Paid', 0, '2026-09-19 07:51:18'),
(22, 7, NULL, 'lab_ready', 'Paid laboratory request ready', 'LAB000001 is cleared for processing', 'laboratory.php?result=Pending&payment=Paid', 0, '2026-09-19 07:51:18'),
(23, 7, NULL, 'lab_result_ready', 'Laboratory result ready', 'LAB000001 has a completed result', 'doctors.php?visit=4', 0, '2026-09-19 07:51:55'),
(24, 5, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000005 for khadiijo abdiwahaab', 'pharmacy.php?section=prescriptions', 0, '2026-09-20 06:28:10'),
(25, 8, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000005 for khadiijo abdiwahaab', 'pharmacy.php?section=prescriptions', 0, '2026-09-20 06:28:10'),
(26, 6, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000005 for khadiijo abdiwahaab', 'pharmacy.php?section=prescriptions', 0, '2026-09-20 06:28:10'),
(27, 7, NULL, 'prescription_created', 'Prescription ready to dispense', 'RX000005 for khadiijo abdiwahaab', 'pharmacy.php?section=prescriptions', 0, '2026-09-20 06:28:10'),
(28, 7, NULL, 'prescription_dispensed', 'Prescription dispensed', 'RX000005 was dispensed.', 'doctors.php?visit=5', 0, '2026-09-20 06:30:12');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `PatientID` int(11) NOT NULL,
  `PatientName` varchar(150) NOT NULL,
  `PatientPhone` varchar(20) DEFAULT NULL,
  `PatientAddress` text DEFAULT NULL,
  `Gender` enum('Male','Female') DEFAULT NULL,
  `Age` tinyint(4) DEFAULT NULL,
  `DateOfBirth` date DEFAULT NULL,
  `PatientType` varchar(50) DEFAULT NULL,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `VisitNumber` int(11) DEFAULT 1,
  `AllocatedDoctor` int(11) DEFAULT NULL,
  `Remark` text DEFAULT NULL,
  `RegisteredAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`PatientID`, `PatientName`, `PatientPhone`, `PatientAddress`, `Gender`, `Age`, `DateOfBirth`, `PatientType`, `DueBalance`, `VisitNumber`, `AllocatedDoctor`, `Remark`, `RegisteredAt`) VALUES
(1, 'Mohamed Ali Abdi', '610508800', 'DIGFERHODAN', 'Male', 26, '2000-05-08', 'New Patient', '0.00', 3, 4, NULL, '2026-08-30 03:46:38'),
(2, 'Mohamed Ali Abdi', '610508800', 'DIGFERHODAN', 'Male', 26, '2000-05-08', 'New Patient', '0.00', 6, 2, NULL, '2026-08-30 04:43:46'),
(3, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, NULL, 'New Patient', '0.00', 4, 4, NULL, '2026-08-30 15:00:26'),
(5, 'hamse abdi qaadir', '614542666', 'hodan', 'Male', 24, '2002-09-19', 'New Patient', '0.00', 1, 1, NULL, '2026-09-18 22:37:03'),
(6, 'axmed abdi nasir', '614807611', 'hodan', 'Male', 22, '2004-09-19', 'New Patient', '0.00', 2, 4, NULL, '2026-09-19 06:51:01'),
(7, 'sharmake hassan', '6123496967', NULL, 'Male', 19, '2006-09-20', 'New Patient', '0.00', 1, NULL, 'dfbgbg', '2026-09-19 16:52:49'),
(10, 'khadiijo abdiwahaab', NULL, 'dayniile', 'Female', 32, '1994-09-20', 'New Patient', '5.50', 2, 4, '.', '2026-09-20 06:22:47'),
(11, 'Saki', '6123496967', NULL, 'Male', 21, '2005-09-20', 'New Patient', '0.00', 1, NULL, 'fvcbnvb', '2026-09-20 08:34:10');

-- --------------------------------------------------------

--
-- Table structure for table `paymentmethods`
--

CREATE TABLE `paymentmethods` (
  `PaymentMethodID` int(11) NOT NULL,
  `MethodName` varchar(80) NOT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `DisplayOrder` int(11) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `paymentmethods`
--

INSERT INTO `paymentmethods` (`PaymentMethodID`, `MethodName`, `Description`, `IsActive`, `DisplayOrder`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'Cash', NULL, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(2, 'Card', NULL, 1, 2, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(3, 'Mobile Money', NULL, 1, 3, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(4, 'Bank', NULL, 1, 4, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(5, 'Other', NULL, 1, 5, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(6, 'EVC Plus', 'Manual EVC Plus mobile wallet transfer (recorded by hand).', 1, 2, '2026-09-18 09:12:22', '2026-09-18 09:12:22'),
(7, 'Bank Transfer', 'Manual bank transfer (recorded by hand).', 1, 4, '2026-09-18 09:12:22', '2026-09-18 09:12:22'),
(8, 'Cheque', 'Manual cheque deposit (recorded by hand).', 1, 6, '2026-09-18 09:12:22', '2026-09-18 09:12:22');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `PaymentID` bigint(20) NOT NULL,
  `PaymentReference` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `VisitID` int(11) DEFAULT NULL,
  `LaboratoryID` varchar(50) DEFAULT NULL,
  `PrescriptionReference` varchar(50) DEFAULT NULL,
  `SaleReference` varchar(50) DEFAULT NULL,
  `PurchaseReference` varchar(50) DEFAULT NULL,
  `PaymentType` enum('Consultation','Laboratory','Pharmacy') NOT NULL,
  `Amount` decimal(10,2) NOT NULL,
  `PaymentMethod` varchar(80) NOT NULL DEFAULT 'Cash',
  `PaymentStatus` enum('Confirmed','Voided') NOT NULL DEFAULT 'Confirmed',
  `ReversalOfPaymentID` bigint(20) DEFAULT NULL,
  `ReversalReference` varchar(50) DEFAULT NULL,
  `ReversalReason` varchar(500) DEFAULT NULL,
  `ReceivedBy` int(11) DEFAULT NULL,
  `PaidAt` datetime NOT NULL DEFAULT current_timestamp(),
  `Notes` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`PaymentID`, `PaymentReference`, `PatientID`, `VisitID`, `LaboratoryID`, `PrescriptionReference`, `SaleReference`, `PurchaseReference`, `PaymentType`, `Amount`, `PaymentMethod`, `PaymentStatus`, `ReversalOfPaymentID`, `ReversalReference`, `ReversalReason`, `ReceivedBy`, `PaidAt`, `Notes`) VALUES
(1, 'PAY000001', 3, 2, NULL, NULL, NULL, NULL, 'Consultation', '5.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 11:16:31', NULL),
(2, 'PAY000002', 1, 3, NULL, NULL, NULL, NULL, 'Consultation', '5.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 11:17:28', NULL),
(3, 'PAY000003', 3, 2, NULL, NULL, NULL, NULL, 'Consultation', '0.50', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 11:17:49', NULL),
(8, 'PAY000004', 3, NULL, NULL, 'RX000001', NULL, NULL, 'Pharmacy', '58.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 11:50:10', NULL),
(9, 'PAY000005', 3, NULL, NULL, 'RX000002', NULL, NULL, 'Pharmacy', '116.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 12:50:44', NULL),
(10, 'PAY000006', 0, NULL, NULL, NULL, 'POS000004', NULL, '', '7.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-18 22:13:48', NULL),
(11, 'PAY000007', 6, 4, NULL, NULL, NULL, NULL, 'Consultation', '5.50', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-19 06:57:37', NULL),
(12, 'PAY000008', 3, NULL, NULL, 'RX000003', NULL, NULL, 'Pharmacy', '8.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-19 07:04:40', NULL),
(13, 'PAY000009', 1, 3, NULL, NULL, NULL, NULL, 'Consultation', '0.50', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-19 07:20:46', NULL),
(14, 'PAY000010', 6, 4, 'LAB000001', NULL, NULL, NULL, 'Laboratory', '1.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-19 07:51:18', NULL),
(15, 'PAY000011', 10, NULL, NULL, 'RX000005', NULL, NULL, 'Pharmacy', '6.00', 'EVC Plus', 'Confirmed', NULL, NULL, NULL, 8, '2026-09-20 06:30:03', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `PermissionID` int(11) NOT NULL,
  `PermissionKey` varchar(150) NOT NULL,
  `ModuleName` varchar(100) NOT NULL,
  `ResourceName` varchar(120) NOT NULL,
  `ActionName` varchar(80) NOT NULL,
  `Description` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`PermissionID`, `PermissionKey`, `ModuleName`, `ResourceName`, `ActionName`, `Description`) VALUES
(1, 'dashboard.view', 'Dashboard', 'Dashboard', 'view', 'View role dashboard'),
(2, 'reception.view', 'Reception', 'Reception workspace', 'view', 'Open reception workspace'),
(3, 'patients.view', 'Patients', 'Patient records', 'view', 'View patient records'),
(4, 'patients.create', 'Patients', 'Patient records', 'create', 'Register patients'),
(5, 'patients.edit', 'Patients', 'Patient records', 'edit', 'Edit patient registration'),
(6, 'patients.delete', 'Patients', 'Patient records', 'delete', 'Delete eligible patient records'),
(7, 'patients.history', 'Patients', 'Patient history', 'view', 'View patient clinical and financial history'),
(8, 'patients.import', 'Patients', 'Patient records', 'import', 'Import patient records from CSV'),
(9, 'patients.export', 'Patients', 'Patient records', 'export', 'Export patient records'),
(10, 'visits.view', 'Visits / Appointments', 'Visits', 'view', 'View visits'),
(11, 'visits.create', 'Visits / Appointments', 'Visits', 'create', 'Create visits'),
(12, 'visits.edit', 'Visits / Appointments', 'Visits', 'edit', 'Edit visits'),
(13, 'visits.assign', 'Visits / Appointments', 'Doctor assignment', 'assign', 'Assign visits to doctors'),
(14, 'doctors.view', 'Doctors', 'Doctor directory', 'view', 'View doctors'),
(15, 'doctors.manage', 'Doctors', 'Doctor directory', 'manage', 'Create and maintain doctor records'),
(16, 'doctors.import', 'Doctors', 'Doctor directory', 'import', 'Import doctor records from CSV'),
(17, 'doctors.export', 'Doctors', 'Doctor directory', 'export', 'Export doctor records'),
(18, 'doctor.workspace', 'Doctors', 'Doctor workspace', 'work', 'Use assigned clinical workspace'),
(19, 'consultations.view', 'Consultations', 'Consultations', 'view', 'View consultations'),
(20, 'consultations.create', 'Consultations', 'Consultations', 'create', 'Book consultations'),
(21, 'consultations.edit', 'Consultations', 'Clinical record', 'edit', 'Record clinical notes'),
(22, 'consultations.complete', 'Consultations', 'Clinical record', 'complete', 'Complete consultations'),
(23, 'laboratory.view', 'Laboratory', 'Laboratory orders', 'view', 'View laboratory orders'),
(24, 'laboratory.request', 'Laboratory', 'Laboratory orders', 'request', 'Request laboratory tests'),
(25, 'laboratory.process', 'Laboratory', 'Laboratory orders', 'process', 'Process paid laboratory orders'),
(26, 'laboratory.result.create', 'Laboratory', 'Laboratory results', 'create', 'Enter laboratory results'),
(27, 'laboratory.result.edit', 'Laboratory', 'Laboratory results', 'edit', 'Edit in-progress laboratory results'),
(28, 'laboratory.complete', 'Laboratory', 'Laboratory orders', 'complete', 'Complete laboratory tests'),
(29, 'laboratory.results.view', 'Laboratory', 'Laboratory results', 'view', 'View laboratory results'),
(30, 'lab_billing.view', 'Laboratory Billing', 'Laboratory bills', 'view', 'View laboratory bills'),
(31, 'lab_billing.payment', 'Laboratory Billing', 'Laboratory payments', 'receive', 'Receive laboratory payments'),
(32, 'pharmacy.view', 'Pharmacy', 'Pharmacy workspace', 'view', 'Open pharmacy workspace'),
(33, 'pharmacy.prescriptions.view', 'Pharmacy', 'Prescriptions', 'view', 'View prescription queue'),
(34, 'pharmacy.prescription.create', 'Pharmacy', 'Prescriptions', 'create', 'Create prescriptions'),
(35, 'pharmacy.dispense', 'Pharmacy', 'Prescriptions', 'dispense', 'Dispense prescriptions'),
(36, 'pharmacy.pos', 'Pharmacy', 'Point of sale', 'sell', 'Use point of sale'),
(37, 'pharmacy.purchases.manage', 'Pharmacy', 'Purchases', 'manage', 'Manage purchases'),
(38, 'pharmacy.inventory.view', 'Inventory', 'Inventory', 'view', 'View medicine inventory'),
(39, 'pharmacy.inventory.manage', 'Inventory', 'Inventory', 'manage', 'Manage medicine inventory'),
(40, 'pharmacy_billing.view', 'Pharmacy Billing', 'Pharmacy bills', 'view', 'View pharmacy billing'),
(41, 'pharmacy_billing.payment', 'Pharmacy Billing', 'Pharmacy payments', 'receive', 'Receive pharmacy payments'),
(42, 'accounting.view', 'Accounting', 'Accounting', 'view', 'View accounting'),
(43, 'accounting.transactions.view', 'Accounting', 'Transactions', 'view', 'View accounting transactions'),
(44, 'accounting.expenses.create', 'Accounting', 'Expenses', 'create', 'Create expense entries'),
(45, 'accounting.expenses.edit', 'Accounting', 'Expenses', 'edit', 'Reverse or correct expense entries'),
(46, 'reports.view', 'Reports', 'Reports', 'view', 'View reports'),
(47, 'reports.export', 'Reports', 'Reports', 'export', 'Export reports'),
(48, 'setup.view', 'Setup', 'Setup', 'view', 'Open system Setup'),
(49, 'setup.organization.manage', 'Setup', 'Organization', 'manage', 'Manage clinic organization'),
(50, 'setup.users.manage', 'Users', 'Users', 'manage', 'Manage user accounts'),
(51, 'setup.roles.manage', 'Roles & Permissions', 'Roles', 'manage', 'Manage roles'),
(52, 'setup.permissions.manage', 'Roles & Permissions', 'Permissions', 'manage', 'Manage role permissions'),
(53, 'setup.clinical.manage', 'Setup', 'Clinical setup', 'manage', 'Manage clinical master data'),
(54, 'setup.laboratory.manage', 'Setup', 'Laboratory setup', 'manage', 'Manage laboratory catalogue'),
(55, 'setup.pharmacy.manage', 'Setup', 'Pharmacy setup', 'manage', 'Manage pharmacy master data'),
(56, 'setup.financial.manage', 'Setup', 'Financial setup', 'manage', 'Manage payment methods'),
(57, 'setup.communication.manage', 'Setup', 'Communication', 'manage', 'Manage supported communications'),
(58, 'setup.system.manage', 'System', 'System setup', 'manage', 'Manage system settings and audit log'),
(59, 'pharmacy.purchase_cost.view', 'Pharmacy', 'Purchase costs', 'view', 'View confidential supplier acquisition costs'),
(60, 'accounting.journal.post', 'Accounting', 'Advanced accounting', 'post', 'Post manual journal entries'),
(61, 'accounting.journal.reverse', 'Accounting', 'Advanced accounting', 'reverse', 'Reverse manual journal entries'),
(62, 'reports.income.cost.view', 'Reports', 'Income Statement', 'view cost', 'View aggregate pharmacy cost and profit in the Income Statement');

-- --------------------------------------------------------

--
-- Table structure for table `pharmacysales`
--

CREATE TABLE `pharmacysales` (
  `SaleID` varchar(50) NOT NULL,
  `ItemID` varchar(50) NOT NULL,
  `ItemName` varchar(150) NOT NULL,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `UnitPrice` decimal(10,2) DEFAULT 0.00,
  `LineTotal` decimal(10,2) DEFAULT 0.00,
  `CostPerUnitSnapshot` decimal(10,4) DEFAULT NULL,
  `LineCost` decimal(10,2) DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PaymentStatus` enum('Paid','Unpaid','Partial') DEFAULT 'Paid',
  `SaleStatus` enum('Valid','Voided') NOT NULL DEFAULT 'Valid',
  `CustomerName` varchar(150) DEFAULT NULL,
  `CustomerPhone` varchar(20) DEFAULT NULL,
  `PatientID` int(11) DEFAULT NULL,
  `VisitID` int(11) DEFAULT NULL,
  `SoldBy` int(11) DEFAULT NULL,
  `SaleDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pharmacysales`
--

INSERT INTO `pharmacysales` (`SaleID`, `ItemID`, `ItemName`, `Quantity`, `UnitPrice`, `LineTotal`, `CostPerUnitSnapshot`, `LineCost`, `TotalAmount`, `AmountPaid`, `DueBalance`, `PaymentStatus`, `SaleStatus`, `CustomerName`, `CustomerPhone`, `PatientID`, `VisitID`, `SoldBy`, `SaleDate`) VALUES
('POS000001-01', 'ITM000001', 'Paractamol', 1, '58.00', '58.00', NULL, NULL, '58.00', '58.00', '0.00', 'Paid', 'Voided', NULL, NULL, NULL, NULL, 8, '2026-09-17 17:23:42'),
('POS000002-01', 'ITM000001', 'Paractamol', 1, '58.00', '58.00', NULL, NULL, '58.00', '0.00', '58.00', 'Unpaid', 'Valid', 'mohamed abdi', '615019253', 3, 2, 8, '2026-09-18 11:20:58'),
('POS000003-01', 'ITM000001', 'Paractamol', 2, '58.00', '116.00', NULL, NULL, '116.00', '116.00', '0.00', 'Paid', 'Valid', 'mohamed abdi', '615019253', 3, 2, 8, '2026-09-18 12:50:56'),
('POS000004-01', 'ITM000002', 'itra cap', 1, '3.00', '3.00', NULL, NULL, '8.00', '7.00', '1.00', 'Partial', 'Valid', NULL, NULL, NULL, NULL, 8, '2026-09-18 22:13:48'),
('POS000004-02', 'ITM000003', 'fintrix cream', 1, '3.00', '3.00', NULL, NULL, '8.00', '7.00', '1.00', 'Partial', 'Valid', NULL, NULL, NULL, NULL, 8, '2026-09-18 22:13:48'),
('POS000004-03', 'ITM000004', 'novale soap', 1, '2.00', '2.00', NULL, NULL, '8.00', '7.00', '1.00', 'Partial', 'Valid', NULL, NULL, NULL, NULL, 8, '2026-09-18 22:13:48'),
('POS000005-01', 'ITM000003', 'fintrix cream', 1, '3.00', '3.00', NULL, NULL, '8.00', '8.00', '0.00', 'Paid', 'Valid', 'mohamed abdi', '615019253', 3, 2, 8, '2026-09-19 07:07:00'),
('POS000005-02', 'ITM000002', 'itra cap', 1, '3.00', '3.00', NULL, NULL, '8.00', '8.00', '0.00', 'Paid', 'Valid', 'mohamed abdi', '615019253', 3, 2, 8, '2026-09-19 07:07:00'),
('POS000005-03', 'ITM000004', 'novale soap', 1, '2.00', '2.00', NULL, NULL, '8.00', '8.00', '0.00', 'Paid', 'Valid', 'mohamed abdi', '615019253', 3, 2, 8, '2026-09-19 07:07:00'),
('POS000006-01', 'ITM000002', 'itra cap', 1, '3.00', '3.00', NULL, NULL, '6.00', '6.00', '0.00', 'Paid', 'Valid', 'khadiijo abdiwahaab', '', 10, 5, 8, '2026-09-20 06:30:12'),
('POS000006-02', 'ITM000003', 'fintrix cream', 1, '3.00', '3.00', NULL, NULL, '6.00', '6.00', '0.00', 'Paid', 'Valid', 'khadiijo abdiwahaab', '', 10, 5, 8, '2026-09-20 06:30:12');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `PrescriptionID` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `VisitID` int(11) DEFAULT NULL,
  `PatientName` varchar(150) NOT NULL,
  `PatientPhone` varchar(20) DEFAULT NULL,
  `PatientAddress` text DEFAULT NULL,
  `Gender` enum('Male','Female') DEFAULT NULL,
  `Age` tinyint(4) DEFAULT NULL,
  `VisitNumber` int(11) DEFAULT 1,
  `DoctorID` int(11) NOT NULL,
  `MedicationName` varchar(150) NOT NULL,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `Route` varchar(50) DEFAULT NULL,
  `Dosage` varchar(100) DEFAULT NULL,
  `Frequency` varchar(100) DEFAULT NULL,
  `Duration` varchar(100) DEFAULT NULL,
  `Instructions` text DEFAULT NULL,
  `Status` enum('Pending','Dispensed','Cancelled') NOT NULL DEFAULT 'Pending',
  `DispensedAt` datetime DEFAULT NULL,
  `DispensedBy` int(11) DEFAULT NULL,
  `PharmacySaleReference` varchar(50) DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PrescriptionDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`PrescriptionID`, `PatientID`, `VisitID`, `PatientName`, `PatientPhone`, `PatientAddress`, `Gender`, `Age`, `VisitNumber`, `DoctorID`, `MedicationName`, `Quantity`, `Route`, `Dosage`, `Frequency`, `Duration`, `Instructions`, `Status`, `DispensedAt`, `DispensedBy`, `PharmacySaleReference`, `TotalAmount`, `AmountPaid`, `DueBalance`, `PrescriptionDate`) VALUES
('RX000001-01', 3, 2, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, 4, 4, 'Paractamol', 1, 'Oral', '2', '6', '1x2', 'dgggjm', 'Dispensed', '2026-09-18 11:20:58', 8, 'POS000002', '58.00', '58.00', '0.00', '2026-09-18 11:20:23'),
('RX000002-01', 3, 2, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, 4, 4, 'Paractamol', 2, 'Oral', '1', '7', '2', 'dhfhfg', 'Dispensed', '2026-09-18 12:50:56', 8, 'POS000003', '116.00', '116.00', '0.00', '2026-09-18 12:49:28'),
('RX000003-01', 3, 2, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, 4, 4, 'fintrix cream', 1, 'Topical', '', '1x2', '10days', 'subax iyo habeen', 'Dispensed', '2026-09-19 07:07:00', 8, 'POS000005', '8.00', '8.00', '0.00', '2026-09-18 21:55:01'),
('RX000003-02', 3, 2, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, 4, 4, 'itra cap', 1, 'Oral', '1x2', '1x2', '10', '1x2', 'Dispensed', '2026-09-19 07:07:00', 8, 'POS000005', '8.00', '8.00', '0.00', '2026-09-18 21:55:01'),
('RX000003-03', 3, 2, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, 4, 4, 'novale soap', 1, 'Topical', '1x2', '1x2', '10', '12', 'Dispensed', '2026-09-19 07:07:00', 8, 'POS000005', '8.00', '8.00', '0.00', '2026-09-18 21:55:01'),
('RX000005-01', 10, 5, 'khadiijo abdiwahaab', NULL, 'dayniile', 'Female', 32, 2, 4, 'itra cap', 1, 'Oral', NULL, '1x2', '10', 'sub kasto', 'Dispensed', '2026-09-20 06:30:12', 8, 'POS000006', '6.00', '6.00', '0.00', '2026-09-20 06:28:10'),
('RX000005-02', 10, 5, 'khadiijo abdiwahaab', NULL, 'dayniile', 'Female', 32, 2, 4, 'fintrix cream', 1, 'Topical', NULL, '1x2', '10', '.', 'Dispensed', '2026-09-20 06:30:12', 8, 'POS000006', '6.00', '6.00', '0.00', '2026-09-20 06:28:10');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptionsheader`
--

CREATE TABLE `prescriptionsheader` (
  `PrescriptionID` varchar(50) NOT NULL,
  `PrescriptionSerial` varchar(50) NOT NULL,
  `CompanyName` varchar(150) NOT NULL,
  `PhoneNumbers` varchar(50) DEFAULT NULL,
  `CompanyAddress` text DEFAULT NULL,
  `CompanyLogo` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `PurchaseID` varchar(50) NOT NULL,
  `SupplierID` int(11) NOT NULL,
  `ItemID` varchar(50) DEFAULT NULL,
  `SupplierName` varchar(150) NOT NULL,
  `SupplierPhone` varchar(20) DEFAULT NULL,
  `Category` varchar(100) DEFAULT NULL,
  `ItemName` varchar(150) NOT NULL,
  `ItemImage` text DEFAULT NULL,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `MinimumQuantity` int(11) DEFAULT 10,
  `PurchaseUnit` varchar(50) DEFAULT NULL,
  `ConversionFactor` decimal(10,2) DEFAULT 1.00,
  `SalesUnit` varchar(50) DEFAULT NULL,
  `UnitPrice` decimal(10,2) DEFAULT 0.00,
  `SellingPrice` decimal(10,2) DEFAULT 0.00,
  `ExpiryDate` date DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PurchaseDate` datetime DEFAULT current_timestamp(),
  `ReferenceNumber` varchar(100) DEFAULT NULL,
  `Discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `VATAmount` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reference_sequences`
--

CREATE TABLE `reference_sequences` (
  `SequenceKey` varchar(80) NOT NULL,
  `NextValue` bigint(20) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reference_sequences`
--

INSERT INTO `reference_sequences` (`SequenceKey`, `NextValue`) VALUES
('accounting.EntryID:JRN', 24),
('laboratory.LaboratoryID:LAB', 1),
('payments.PaymentReference:PAY', 11),
('prescriptions.PrescriptionID:RX', 5),
('visits.VisitReference:VIS', 8);

-- --------------------------------------------------------

--
-- Table structure for table `rolepermissions`
--

CREATE TABLE `rolepermissions` (
  `RoleID` int(11) NOT NULL,
  `PermissionID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rolepermissions`
--

INSERT INTO `rolepermissions` (`RoleID`, `PermissionID`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 1),
(1, 2),
(2, 2),
(3, 2),
(1, 3),
(2, 3),
(3, 3),
(1, 4),
(2, 4),
(3, 4),
(1, 5),
(2, 5),
(3, 5),
(1, 6),
(2, 6),
(3, 6),
(1, 7),
(2, 7),
(3, 7),
(1, 8),
(2, 8),
(3, 8),
(1, 9),
(2, 9),
(3, 9),
(1, 10),
(2, 10),
(3, 10),
(1, 11),
(2, 11),
(3, 11),
(1, 12),
(2, 12),
(3, 12),
(1, 13),
(2, 13),
(3, 13),
(1, 14),
(2, 14),
(3, 14),
(1, 15),
(3, 15),
(1, 16),
(3, 16),
(1, 17),
(2, 17),
(3, 17),
(1, 18),
(3, 18),
(1, 19),
(2, 19),
(3, 19),
(1, 20),
(2, 20),
(1, 21),
(3, 21),
(1, 22),
(3, 22),
(1, 23),
(2, 23),
(3, 23),
(4, 23),
(1, 24),
(3, 24),
(1, 25),
(2, 25),
(3, 25),
(4, 25),
(1, 26),
(2, 26),
(3, 26),
(4, 26),
(1, 27),
(2, 27),
(3, 27),
(4, 27),
(1, 28),
(2, 28),
(3, 28),
(4, 28),
(1, 29),
(2, 29),
(3, 29),
(4, 29),
(1, 30),
(2, 30),
(1, 31),
(2, 31),
(1, 32),
(2, 32),
(3, 32),
(5, 32),
(1, 33),
(2, 33),
(3, 33),
(5, 33),
(1, 34),
(3, 34),
(5, 34),
(1, 35),
(2, 35),
(3, 35),
(5, 35),
(1, 36),
(2, 36),
(3, 36),
(5, 36),
(1, 37),
(3, 37),
(5, 37),
(1, 38),
(2, 38),
(5, 38),
(1, 39),
(2, 39),
(5, 39),
(1, 40),
(2, 40),
(3, 40),
(5, 40),
(1, 41),
(2, 41),
(3, 41),
(5, 41),
(1, 42),
(2, 42),
(3, 42),
(1, 43),
(2, 43),
(3, 43),
(1, 44),
(2, 44),
(3, 44),
(1, 45),
(2, 45),
(3, 45),
(1, 46),
(2, 46),
(3, 46),
(1, 47),
(2, 47),
(3, 47),
(1, 48),
(3, 48),
(1, 49),
(3, 49),
(1, 50),
(3, 50),
(1, 51),
(3, 51),
(1, 52),
(3, 52),
(1, 53),
(3, 53),
(1, 54),
(3, 54),
(1, 55),
(3, 55),
(1, 56),
(3, 56),
(1, 57),
(3, 57),
(1, 58),
(3, 58),
(1, 59),
(3, 59),
(5, 59),
(1, 60),
(3, 60),
(1, 61),
(3, 61),
(1, 62),
(3, 62);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `RoleID` int(11) NOT NULL,
  `RoleKey` varchar(100) NOT NULL,
  `RoleName` varchar(100) NOT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `IsSystem` tinyint(1) NOT NULL DEFAULT 0,
  `IsProtected` tinyint(1) NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`RoleID`, `RoleKey`, `RoleName`, `Description`, `IsSystem`, `IsProtected`, `IsActive`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'superuser', 'SuperAdmin', 'Full system administration and operational access.', 1, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(2, 'receptionuser', 'Reception', 'Combined reception, pharmacy, laboratory and accounting operations. Acquisition cost and administration remain restricted.', 1, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(3, 'doctoruser', 'Doctor', 'Assigned consultations, prescriptions and laboratory requests.', 1, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(4, 'labuser', 'Laboratory', 'Laboratory order processing and clinical results.', 1, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04'),
(5, 'pharmacyuser', 'Pharmacy', 'Prescription dispensing and point-of-sale operations.', 1, 1, 1, '2026-09-16 06:36:04', '2026-09-16 06:36:04');

-- --------------------------------------------------------

--
-- Table structure for table `specializations`
--

CREATE TABLE `specializations` (
  `SpecializationID` int(11) NOT NULL,
  `SpecializationName` varchar(120) NOT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `specializations`
--

INSERT INTO `specializations` (`SpecializationID`, `SpecializationName`, `Description`, `IsActive`) VALUES
(1, 'Dermatology', NULL, 1),
(2, 'Derma', NULL, 1),
(3, 'dermatologist', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `userlegalname` varchar(255) NOT NULL,
  `role` varchar(100) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_root` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `userlegalname`, `role`, `role_id`, `username`, `password`, `is_active`, `is_root`, `last_login_at`, `created_at`, `updated_at`) VALUES
(5, 'Moalio Tech Solutions', 'superuser', 1, 'Moalio', '$2b$10$X9uCZSx2CJICHwu5N3OcU.mMqr3N93HMmC2BvxK9xpHXBXPoWvz.C', 1, 0, NULL, '2026-07-16 10:21:29', '2026-09-16 13:36:04'),
(6, 'Dr. Mohamed Abdi Hashi', 'receptionuser', 2, 'tarey', '$2y$10$KaFQ2cNXN9fG48Cp8ySU0e6PqXw4.YZrtLj5QTlV/HyJUMNMI1T0m', 1, 0, '2026-09-18 09:15:31', '2026-07-16 11:10:33', '2026-09-18 16:15:31'),
(7, 'Dr. Abdalla Mohamed Hashi', 'doctoruser', 3, 'Tareey', '$2y$10$yyI7pp2zelNWFAx71to9OOMd937wFYaK1jRJvKmMQEuWDBRUYIpgu', 1, 0, NULL, '2026-08-29 08:35:23', '2026-09-16 15:07:21'),
(8, 'System Super Administrator', 'superuser', 1, 'Superadmin', '$2y$10$Br1LwrWYOW0vFQkLBHp0Aeg0zwGl4v5F0xk6KZu82wKl7zK9JmKS6', 1, 1, '2026-09-20 06:13:31', '2026-09-16 14:04:45', '2026-09-20 13:13:31');

-- --------------------------------------------------------

--
-- Table structure for table `visits`
--

CREATE TABLE `visits` (
  `VisitID` int(11) NOT NULL,
  `VisitReference` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `DoctorID` int(11) NOT NULL,
  `ReceptionistUserID` int(11) DEFAULT NULL,
  `VisitDate` datetime NOT NULL DEFAULT current_timestamp(),
  `ConsultationFee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `IsFreeConsultation` tinyint(1) NOT NULL DEFAULT 0,
  `AmountPaid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `DueBalance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `PaymentStatus` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
  `QueueStatus` enum('Pending Payment','Waiting','In Consultation','Completed','Cancelled') NOT NULL DEFAULT 'Pending Payment',
  `ChiefComplaint` text DEFAULT NULL,
  `ClinicalNotes` text DEFAULT NULL,
  `Diagnosis` text DEFAULT NULL,
  `TreatmentPlan` text DEFAULT NULL,
  `FollowUpPlan` text DEFAULT NULL,
  `FollowUpDate` date DEFAULT NULL,
  `CompletedAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `visits`
--

INSERT INTO `visits` (`VisitID`, `VisitReference`, `PatientID`, `DoctorID`, `ReceptionistUserID`, `VisitDate`, `ConsultationFee`, `AmountPaid`, `DueBalance`, `PaymentStatus`, `QueueStatus`, `ChiefComplaint`, `ClinicalNotes`, `Diagnosis`, `TreatmentPlan`, `FollowUpPlan`, `FollowUpDate`, `CompletedAt`, `CreatedAt`, `UpdatedAt`) VALUES
(2, 'VIS000002', 3, 4, 8, '2026-09-21 09:00:00', '5.50', '5.50', '0.00', 'Paid', 'Completed', 'dfhfgjhj', 'cvfgfhfh', 'gdfhdgjhg', 'vbnbn', 'gjjbhmbm', '2026-09-19', '2026-09-18 14:18:43', '2026-09-18 11:16:31', '2026-09-18 11:18:43'),
(3, 'VIS000004', 1, 4, 8, '2026-09-23 09:00:00', '5.50', '5.50', '0.00', 'Paid', 'Completed', 'dfdgh', NULL, NULL, NULL, NULL, NULL, '2026-09-19 15:00:09', '2026-09-18 11:17:28', '2026-09-19 15:00:09'),
(4, 'VIS000007', 6, 4, 8, '2026-09-22 10:00:00', '5.50', '5.50', '0.00', 'Paid', 'Completed', 'fgd', 'ichting', 'scabies', 'scabeis treament', '', '2026-09-19', '2026-09-19 10:00:24', '2026-09-19 06:57:37', '2026-09-19 07:00:25'),
(5, 'VIS000008', 10, 4, 8, '2026-09-23 16:22:00', '5.50', '0.00', '5.50', 'Unpaid', 'Completed', '.', 'headech', 'malaria', '', '2', '2026-09-30', '2026-09-20 09:25:22', '2026-09-20 06:22:47', '2026-09-20 06:25:23');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounting`
--
ALTER TABLE `accounting`
  ADD PRIMARY KEY (`EntryID`);

--
-- Indexes for table `auditlog`
--
ALTER TABLE `auditlog`
  ADD PRIMARY KEY (`AuditID`),
  ADD KEY `idx_audit_created` (`CreatedAt`),
  ADD KEY `idx_audit_actor` (`ActorUserID`);

--
-- Indexes for table `clinicsettings`
--
ALTER TABLE `clinicsettings`
  ADD PRIMARY KEY (`SettingKey`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`DepartmentID`),
  ADD UNIQUE KEY `uq_departments_name` (`DepartmentName`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`DoctorID`),
  ADD UNIQUE KEY `uq_doctors_user` (`UserID`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`ItemID`);

--
-- Indexes for table `laboratory`
--
ALTER TABLE `laboratory`
  ADD PRIMARY KEY (`LaboratoryID`),
  ADD KEY `idx_laboratory_visit` (`VisitID`),
  ADD KEY `idx_laboratory_workflow` (`WorkflowStatus`,`PaymentStatus`,`OrderDate`);

--
-- Indexes for table `laborderitems`
--
ALTER TABLE `laborderitems`
  ADD PRIMARY KEY (`LabOrderItemID`),
  ADD UNIQUE KEY `uq_lab_order_service` (`LaboratoryID`,`ServiceID`),
  ADD KEY `idx_lab_order_items_order` (`LaboratoryID`),
  ADD KEY `idx_lab_order_items_service` (`ServiceID`);

--
-- Indexes for table `labservices`
--
ALTER TABLE `labservices`
  ADD PRIMARY KEY (`ServiceID`),
  ADD UNIQUE KEY `uq_lab_services_name` (`ServiceName`),
  ADD KEY `idx_lab_services_active` (`IsActive`,`ServiceName`);

--
-- Indexes for table `lab_categories`
--
ALTER TABLE `lab_categories`
  ADD PRIMARY KEY (`CategoryID`),
  ADD UNIQUE KEY `uq_lab_categories_name` (`CategoryName`),
  ADD KEY `idx_lab_categories_active` (`IsActive`,`DisplayOrder`);

--
-- Indexes for table `lab_centers`
--
ALTER TABLE `lab_centers`
  ADD PRIMARY KEY (`LabCenterID`),
  ADD UNIQUE KEY `uq_lab_centers_name` (`CenterName`),
  ADD KEY `idx_lab_centers_active` (`IsActive`);

--
-- Indexes for table `lab_center_tests`
--
ALTER TABLE `lab_center_tests`
  ADD PRIMARY KEY (`CenterTestID`),
  ADD UNIQUE KEY `uq_lab_center_test` (`LabCenterID`,`TestID`),
  ADD KEY `idx_lab_center_tests_center` (`LabCenterID`,`IsActive`),
  ADD KEY `idx_lab_center_tests_test` (`TestID`,`IsActive`);

--
-- Indexes for table `lab_flags`
--
ALTER TABLE `lab_flags`
  ADD PRIMARY KEY (`FlagID`),
  ADD UNIQUE KEY `uq_lab_flags_code` (`FlagCode`),
  ADD UNIQUE KEY `uq_lab_flags_name` (`FlagName`),
  ADD KEY `idx_lab_flags_active` (`IsActive`);

--
-- Indexes for table `lab_order_catalog_bridge`
--
ALTER TABLE `lab_order_catalog_bridge`
  ADD PRIMARY KEY (`BridgeID`),
  ADD UNIQUE KEY `uq_lab_bridge_modern_test` (`LaboratoryID`,`ModernTestID`),
  ADD UNIQUE KEY `uq_lab_bridge_legacy_service` (`LaboratoryID`,`LegacyServiceID`),
  ADD KEY `idx_lab_bridge_laboratory` (`LaboratoryID`),
  ADD KEY `idx_lab_bridge_modern_test_lookup` (`ModernTestID`),
  ADD KEY `idx_lab_bridge_service` (`LegacyServiceID`),
  ADD KEY `idx_lab_bridge_active` (`IsActive`);

--
-- Indexes for table `lab_parameters`
--
ALTER TABLE `lab_parameters`
  ADD PRIMARY KEY (`ParameterID`),
  ADD UNIQUE KEY `uq_lab_parameters_test_name` (`TestID`,`ParameterName`),
  ADD KEY `idx_lab_parameters_test` (`TestID`,`IsActive`),
  ADD KEY `idx_lab_parameters_unit` (`UnitID`);

--
-- Indexes for table `lab_results`
--
ALTER TABLE `lab_results`
  ADD PRIMARY KEY (`LabResultID`),
  ADD UNIQUE KEY `uq_lab_results_bridge` (`BridgeID`),
  ADD KEY `idx_lab_results_center` (`LabCenterID`),
  ADD KEY `idx_lab_results_status` (`ResultStatus`),
  ADD KEY `fk_lab_results_collected_by` (`CollectedBy`),
  ADD KEY `fk_lab_results_completed_by` (`CompletedBy`),
  ADD KEY `fk_lab_results_reviewed_by` (`ReviewedBy`);

--
-- Indexes for table `lab_result_attachments`
--
ALTER TABLE `lab_result_attachments`
  ADD PRIMARY KEY (`AttachmentID`),
  ADD KEY `idx_lab_result_attachments_result` (`LabResultID`),
  ADD KEY `fk_lab_result_attachments_user` (`UploadedBy`);

--
-- Indexes for table `lab_result_parameters`
--
ALTER TABLE `lab_result_parameters`
  ADD PRIMARY KEY (`LabResultParameterID`),
  ADD UNIQUE KEY `uq_lab_result_parameter_once` (`LabResultID`,`ParameterID`),
  ADD KEY `idx_lab_result_parameters_result` (`LabResultID`),
  ADD KEY `idx_lab_result_parameters_parameter` (`ParameterID`),
  ADD KEY `idx_lab_result_parameters_unit` (`UnitID`),
  ADD KEY `idx_lab_result_parameters_flag` (`FlagID`);

--
-- Indexes for table `lab_result_review`
--
ALTER TABLE `lab_result_review`
  ADD PRIMARY KEY (`ReviewID`),
  ADD KEY `idx_lab_result_review_result` (`LabResultID`,`PerformedAt`),
  ADD KEY `fk_lab_result_review_user` (`PerformedBy`);

--
-- Indexes for table `lab_tests`
--
ALTER TABLE `lab_tests`
  ADD PRIMARY KEY (`TestID`),
  ADD UNIQUE KEY `uq_lab_tests_name` (`TypeID`,`TestName`),
  ADD KEY `idx_lab_tests_type` (`TypeID`,`IsActive`);

--
-- Indexes for table `lab_test_selection`
--
ALTER TABLE `lab_test_selection`
  ADD PRIMARY KEY (`SelectionID`),
  ADD UNIQUE KEY `uq_lab_test_selection` (`TestID`,`DoctorID`,`LabCenterID`),
  ADD KEY `idx_lab_test_selection_enabled` (`IsEnabled`,`TestID`),
  ADD KEY `idx_lab_test_selection_doctor` (`DoctorID`,`IsEnabled`),
  ADD KEY `idx_lab_test_selection_center` (`LabCenterID`,`IsEnabled`);

--
-- Indexes for table `lab_types`
--
ALTER TABLE `lab_types`
  ADD PRIMARY KEY (`TypeID`),
  ADD UNIQUE KEY `uq_lab_types_name` (`CategoryID`,`TypeName`),
  ADD KEY `idx_lab_types_category` (`CategoryID`,`IsActive`);

--
-- Indexes for table `lab_units`
--
ALTER TABLE `lab_units`
  ADD PRIMARY KEY (`UnitID`),
  ADD UNIQUE KEY `uq_lab_units_symbol` (`UnitSymbol`),
  ADD UNIQUE KEY `uq_lab_units_name` (`UnitName`),
  ADD KEY `idx_lab_units_active` (`IsActive`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`AttemptKey`),
  ADD KEY `idx_login_attempts_blocked` (`BlockedUntil`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`NotificationID`),
  ADD KEY `idx_notifications_user` (`UserID`,`IsRead`,`CreatedAt`),
  ADD KEY `idx_notifications_role` (`RoleTarget`,`IsRead`,`CreatedAt`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`PatientID`);

--
-- Indexes for table `paymentmethods`
--
ALTER TABLE `paymentmethods`
  ADD PRIMARY KEY (`PaymentMethodID`),
  ADD UNIQUE KEY `uq_payment_methods_name` (`MethodName`),
  ADD KEY `idx_payment_methods_active` (`IsActive`,`DisplayOrder`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`PaymentID`),
  ADD UNIQUE KEY `uq_payments_reference` (`PaymentReference`),
  ADD KEY `idx_payments_patient` (`PatientID`),
  ADD KEY `idx_payments_visit` (`VisitID`),
  ADD KEY `idx_payments_type_date` (`PaymentType`,`PaidAt`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`PermissionID`),
  ADD UNIQUE KEY `uq_permissions_key` (`PermissionKey`),
  ADD KEY `idx_permissions_module` (`ModuleName`,`ResourceName`);

--
-- Indexes for table `pharmacysales`
--
ALTER TABLE `pharmacysales`
  ADD PRIMARY KEY (`SaleID`),
  ADD KEY `idx_pharmacy_sales_patient` (`PatientID`),
  ADD KEY `idx_pharmacy_sales_visit` (`VisitID`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`PrescriptionID`),
  ADD KEY `idx_prescriptions_visit` (`VisitID`),
  ADD KEY `idx_prescriptions_status` (`Status`,`PrescriptionDate`);

--
-- Indexes for table `prescriptionsheader`
--
ALTER TABLE `prescriptionsheader`
  ADD PRIMARY KEY (`PrescriptionID`),
  ADD UNIQUE KEY `PrescriptionSerial` (`PrescriptionSerial`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`PurchaseID`),
  ADD KEY `idx_purchases_item_date` (`ItemID`,`PurchaseDate`);

--
-- Indexes for table `reference_sequences`
--
ALTER TABLE `reference_sequences`
  ADD PRIMARY KEY (`SequenceKey`);

--
-- Indexes for table `rolepermissions`
--
ALTER TABLE `rolepermissions`
  ADD PRIMARY KEY (`RoleID`,`PermissionID`),
  ADD KEY `idx_role_permissions_permission` (`PermissionID`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`RoleID`),
  ADD UNIQUE KEY `uq_roles_key` (`RoleKey`),
  ADD UNIQUE KEY `uq_roles_name` (`RoleName`);

--
-- Indexes for table `specializations`
--
ALTER TABLE `specializations`
  ADD PRIMARY KEY (`SpecializationID`),
  ADD UNIQUE KEY `uq_specializations_name` (`SpecializationName`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_users_role_id` (`role_id`);

--
-- Indexes for table `visits`
--
ALTER TABLE `visits`
  ADD PRIMARY KEY (`VisitID`),
  ADD UNIQUE KEY `uq_visits_reference` (`VisitReference`),
  ADD KEY `idx_visits_patient` (`PatientID`),
  ADD KEY `idx_visits_doctor_queue` (`DoctorID`,`QueueStatus`,`VisitDate`),
  ADD KEY `idx_visits_payment` (`PaymentStatus`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `auditlog`
--
ALTER TABLE `auditlog`
  MODIFY `AuditID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `DepartmentID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `DoctorID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `laborderitems`
--
ALTER TABLE `laborderitems`
  MODIFY `LabOrderItemID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `labservices`
--
ALTER TABLE `labservices`
  MODIFY `ServiceID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lab_categories`
--
ALTER TABLE `lab_categories`
  MODIFY `CategoryID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `lab_centers`
--
ALTER TABLE `lab_centers`
  MODIFY `LabCenterID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_center_tests`
--
ALTER TABLE `lab_center_tests`
  MODIFY `CenterTestID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_flags`
--
ALTER TABLE `lab_flags`
  MODIFY `FlagID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `lab_order_catalog_bridge`
--
ALTER TABLE `lab_order_catalog_bridge`
  MODIFY `BridgeID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_parameters`
--
ALTER TABLE `lab_parameters`
  MODIFY `ParameterID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_results`
--
ALTER TABLE `lab_results`
  MODIFY `LabResultID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_result_attachments`
--
ALTER TABLE `lab_result_attachments`
  MODIFY `AttachmentID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_result_parameters`
--
ALTER TABLE `lab_result_parameters`
  MODIFY `LabResultParameterID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_result_review`
--
ALTER TABLE `lab_result_review`
  MODIFY `ReviewID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_tests`
--
ALTER TABLE `lab_tests`
  MODIFY `TestID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_test_selection`
--
ALTER TABLE `lab_test_selection`
  MODIFY `SelectionID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_types`
--
ALTER TABLE `lab_types`
  MODIFY `TypeID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_units`
--
ALTER TABLE `lab_units`
  MODIFY `UnitID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `NotificationID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `PatientID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `paymentmethods`
--
ALTER TABLE `paymentmethods`
  MODIFY `PaymentMethodID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `PaymentID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `PermissionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `RoleID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `specializations`
--
ALTER TABLE `specializations`
  MODIFY `SpecializationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `visits`
--
ALTER TABLE `visits`
  MODIFY `VisitID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `lab_center_tests`
--
ALTER TABLE `lab_center_tests`
  ADD CONSTRAINT `fk_lab_center_tests_center` FOREIGN KEY (`LabCenterID`) REFERENCES `lab_centers` (`LabCenterID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_center_tests_test` FOREIGN KEY (`TestID`) REFERENCES `lab_tests` (`TestID`) ON UPDATE CASCADE;

--
-- Constraints for table `lab_order_catalog_bridge`
--
ALTER TABLE `lab_order_catalog_bridge`
  ADD CONSTRAINT `fk_lab_bridge_laboratory` FOREIGN KEY (`LaboratoryID`) REFERENCES `laboratory` (`LaboratoryID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_bridge_modern_test` FOREIGN KEY (`ModernTestID`) REFERENCES `lab_tests` (`TestID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_bridge_service` FOREIGN KEY (`LegacyServiceID`) REFERENCES `labservices` (`ServiceID`) ON UPDATE CASCADE;

--
-- Constraints for table `lab_parameters`
--
ALTER TABLE `lab_parameters`
  ADD CONSTRAINT `fk_lab_parameters_test` FOREIGN KEY (`TestID`) REFERENCES `lab_tests` (`TestID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_parameters_unit` FOREIGN KEY (`UnitID`) REFERENCES `lab_units` (`UnitID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `lab_results`
--
ALTER TABLE `lab_results`
  ADD CONSTRAINT `fk_lab_results_bridge` FOREIGN KEY (`BridgeID`) REFERENCES `lab_order_catalog_bridge` (`BridgeID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_results_center` FOREIGN KEY (`LabCenterID`) REFERENCES `lab_centers` (`LabCenterID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_results_collected_by` FOREIGN KEY (`CollectedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_results_completed_by` FOREIGN KEY (`CompletedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_results_reviewed_by` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `lab_result_attachments`
--
ALTER TABLE `lab_result_attachments`
  ADD CONSTRAINT `fk_lab_result_attachments_result` FOREIGN KEY (`LabResultID`) REFERENCES `lab_results` (`LabResultID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_result_attachments_user` FOREIGN KEY (`UploadedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `lab_result_parameters`
--
ALTER TABLE `lab_result_parameters`
  ADD CONSTRAINT `fk_lab_result_parameters_flag` FOREIGN KEY (`FlagID`) REFERENCES `lab_flags` (`FlagID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_result_parameters_parameter` FOREIGN KEY (`ParameterID`) REFERENCES `lab_parameters` (`ParameterID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_result_parameters_result` FOREIGN KEY (`LabResultID`) REFERENCES `lab_results` (`LabResultID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_result_parameters_unit` FOREIGN KEY (`UnitID`) REFERENCES `lab_units` (`UnitID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `lab_result_review`
--
ALTER TABLE `lab_result_review`
  ADD CONSTRAINT `fk_lab_result_review_result` FOREIGN KEY (`LabResultID`) REFERENCES `lab_results` (`LabResultID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_result_review_user` FOREIGN KEY (`PerformedBy`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `lab_tests`
--
ALTER TABLE `lab_tests`
  ADD CONSTRAINT `fk_lab_tests_type` FOREIGN KEY (`TypeID`) REFERENCES `lab_types` (`TypeID`) ON UPDATE CASCADE;

--
-- Constraints for table `lab_test_selection`
--
ALTER TABLE `lab_test_selection`
  ADD CONSTRAINT `fk_lab_test_selection_center` FOREIGN KEY (`LabCenterID`) REFERENCES `lab_centers` (`LabCenterID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_test_selection_doctor` FOREIGN KEY (`DoctorID`) REFERENCES `doctors` (`DoctorID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lab_test_selection_test` FOREIGN KEY (`TestID`) REFERENCES `lab_tests` (`TestID`) ON UPDATE CASCADE;

--
-- Constraints for table `lab_types`
--
ALTER TABLE `lab_types`
  ADD CONSTRAINT `fk_lab_types_category` FOREIGN KEY (`CategoryID`) REFERENCES `lab_categories` (`CategoryID`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
