-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 14, 2026 at 01:27 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tareydermaclinic`
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

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `DoctorID` int(11) NOT NULL,
  `DoctorName` varchar(150) NOT NULL,
  `ConsultationFee` decimal(10,2) DEFAULT 0.00,
  `Specialty` varchar(100) DEFAULT NULL,
  `JoinedDate` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`DoctorID`, `DoctorName`, `ConsultationFee`, `Specialty`, `JoinedDate`) VALUES
(1, 'Mohamed Ali Abid', 5.00, 'Dermatology', '2026-08-30'),
(2, 'Dr Abdalla Osman', 8.00, 'Derma', '2026-08-30'),
(3, 'Dr Zakaria Ali', 100.00, 'Dermatology', '2026-08-30'),
(4, 'dr tarey', 5.50, 'dermatologist', '2026-08-01');

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
  `LastUpdated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratory`
--

CREATE TABLE `laboratory` (
  `LaboratoryID` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `TestID` int(11) NOT NULL,
  `TestName` varchar(150) NOT NULL,
  `Description` text DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PaymentStatus` enum('Paid','Unpaid','Partial') DEFAULT 'Unpaid',
  `IsAvailable` tinyint(1) DEFAULT 1,
  `OrderDate` datetime DEFAULT current_timestamp(),
  `Result` enum('Positive','Negative','Pending') DEFAULT 'Pending',
  `ResultDate` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Mohamed Ali Abdi', '610508800', 'DIGFERHODAN', 'Male', 26, '2000-05-08', 'New Patient', 0.00, 2, 1, NULL, '2026-08-30 03:46:38'),
(2, 'Mohamed Ali Abdi', '610508800', 'DIGFERHODAN', 'Male', 26, '2000-05-08', 'New Patient', 0.00, 6, 2, NULL, '2026-08-30 04:43:46'),
(3, 'mohamed abdi', '615019253', 'hodan', 'Male', 34, NULL, 'New Patient', 0.00, 3, 4, NULL, '2026-08-30 15:00:26');

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
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PaymentStatus` enum('Paid','Unpaid','Partial') DEFAULT 'Paid',
  `CustomerName` varchar(150) DEFAULT NULL,
  `CustomerPhone` varchar(20) DEFAULT NULL,
  `SoldBy` int(11) DEFAULT NULL,
  `SaleDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `PrescriptionID` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `PatientName` varchar(150) NOT NULL,
  `PatientPhone` varchar(20) DEFAULT NULL,
  `PatientAddress` text DEFAULT NULL,
  `Gender` enum('Male','Female') DEFAULT NULL,
  `Age` tinyint(4) DEFAULT NULL,
  `VisitNumber` int(11) DEFAULT 1,
  `DoctorID` int(11) NOT NULL,
  `MedicationName` varchar(150) NOT NULL,
  `Dosage` varchar(100) DEFAULT NULL,
  `Frequency` varchar(100) DEFAULT NULL,
  `Duration` varchar(100) DEFAULT NULL,
  `Instructions` text DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PrescriptionDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `TotalAmount` decimal(10,2) DEFAULT 0.00,
  `AmountPaid` decimal(10,2) DEFAULT 0.00,
  `DueBalance` decimal(10,2) DEFAULT 0.00,
  `PurchaseDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `userlegalname` varchar(255) NOT NULL,
  `role` enum('superuser','receptionuser','pharmacyuser','labuser') NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `userlegalname`, `role`, `username`, `password`, `created_at`, `updated_at`) VALUES
(5, 'Moalio Tech Solutions', 'superuser', 'Moalio', '$2b$10$X9uCZSx2CJICHwu5N3OcU.mMqr3N93HMmC2BvxK9xpHXBXPoWvz.C', '2026-07-16 10:21:29', '2026-08-30 09:55:14'),
(6, 'Dr. Mohamed Abdi Hashi', 'superuser', 'tarey', '$2y$10$A8VRiu9mw3zW88X9EPPQ9uwzh9H.RIhdANZcwkyjBPq3XCbUXUO/6', '2026-07-16 11:10:33', '2026-08-30 11:56:11'),
(7, 'Dr. Abdalla Mohamed Hashi', 'superuser', 'Tareey', '$2y$10$XRlDCCSx9liqleo3vz0DuOu728wRKWmucbzQk.jVhnZuOyRIt0XnG', '2026-08-29 08:35:23', '2026-08-30 09:54:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounting`
--
ALTER TABLE `accounting`
  ADD PRIMARY KEY (`EntryID`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`DoctorID`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`ItemID`);

--
-- Indexes for table `laboratory`
--
ALTER TABLE `laboratory`
  ADD PRIMARY KEY (`LaboratoryID`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`PatientID`);

--
-- Indexes for table `pharmacysales`
--
ALTER TABLE `pharmacysales`
  ADD PRIMARY KEY (`SaleID`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`PrescriptionID`);

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
  ADD PRIMARY KEY (`PurchaseID`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `DoctorID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `PatientID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

-- Connected clinic workflow (Doctor portal, consultation queue, payments,
-- prescription dispensing, laboratory handoff, and notifications).
ALTER TABLE `users`
  MODIFY `role` enum('superuser','receptionuser','doctoruser','pharmacyuser','labuser') NOT NULL;

ALTER TABLE `doctors`
  ADD COLUMN `UserID` int(11) DEFAULT NULL AFTER `DoctorID`,
  ADD UNIQUE KEY `uq_doctors_user` (`UserID`);

ALTER TABLE `prescriptions`
  ADD COLUMN `VisitID` int(11) DEFAULT NULL AFTER `PatientID`,
  ADD COLUMN `Quantity` int(11) NOT NULL DEFAULT 1 AFTER `MedicationName`,
  ADD COLUMN `Status` enum('Pending','Dispensed','Cancelled') NOT NULL DEFAULT 'Pending' AFTER `Instructions`,
  ADD COLUMN `DispensedAt` datetime DEFAULT NULL AFTER `Status`,
  ADD COLUMN `DispensedBy` int(11) DEFAULT NULL AFTER `DispensedAt`,
  ADD COLUMN `PharmacySaleReference` varchar(50) DEFAULT NULL AFTER `DispensedBy`,
  ADD KEY `idx_prescriptions_visit` (`VisitID`),
  ADD KEY `idx_prescriptions_status` (`Status`,`PrescriptionDate`);

ALTER TABLE `laboratory`
  ADD COLUMN `VisitID` int(11) DEFAULT NULL AFTER `PatientID`,
  ADD COLUMN `DoctorID` int(11) DEFAULT NULL AFTER `VisitID`,
  ADD COLUMN `RequestedByUserID` int(11) DEFAULT NULL AFTER `DoctorID`,
  ADD COLUMN `ServiceID` int(11) DEFAULT NULL AFTER `RequestedByUserID`,
  ADD COLUMN `WorkflowStatus` enum('Requested','Awaiting Payment','Ready','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Awaiting Payment' AFTER `PaymentStatus`,
  ADD COLUMN `ClinicalResult` text DEFAULT NULL AFTER `Result`,
  ADD COLUMN `ReviewedAt` datetime DEFAULT NULL AFTER `ResultDate`,
  ADD KEY `idx_laboratory_visit` (`VisitID`),
  ADD KEY `idx_laboratory_workflow` (`WorkflowStatus`,`PaymentStatus`,`OrderDate`);

CREATE TABLE `labservices` (
  `ServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `ServiceName` varchar(150) NOT NULL,
  `Category` varchar(100) DEFAULT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `Price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `IsAvailable` tinyint(1) NOT NULL DEFAULT 1,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ServiceID`),
  UNIQUE KEY `uq_lab_services_name` (`ServiceName`),
  KEY `idx_lab_services_active` (`IsActive`,`ServiceName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `laborderitems` (
  `LabOrderItemID` bigint(20) NOT NULL AUTO_INCREMENT,
  `LaboratoryID` varchar(50) NOT NULL,
  `ServiceID` int(11) NOT NULL,
  `TestName` varchar(150) NOT NULL,
  `UnitPrice` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Result` enum('Positive','Negative','Pending') NOT NULL DEFAULT 'Pending',
  `ClinicalResult` text DEFAULT NULL,
  `ResultDate` datetime DEFAULT NULL,
  PRIMARY KEY (`LabOrderItemID`),
  UNIQUE KEY `uq_lab_order_service` (`LaboratoryID`,`ServiceID`),
  KEY `idx_lab_order_items_order` (`LaboratoryID`),
  KEY `idx_lab_order_items_service` (`ServiceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `visits` (
  `VisitID` int(11) NOT NULL AUTO_INCREMENT,
  `VisitReference` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL,
  `DoctorID` int(11) NOT NULL,
  `ReceptionistUserID` int(11) DEFAULT NULL,
  `VisitDate` datetime NOT NULL DEFAULT current_timestamp(),
  `ConsultationFee` decimal(10,2) NOT NULL DEFAULT 0.00,
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
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`VisitID`), UNIQUE KEY `uq_visits_reference` (`VisitReference`),
  KEY `idx_visits_patient` (`PatientID`), KEY `idx_visits_doctor_queue` (`DoctorID`,`QueueStatus`,`VisitDate`), KEY `idx_visits_payment` (`PaymentStatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `PaymentID` bigint(20) NOT NULL AUTO_INCREMENT, `PaymentReference` varchar(50) NOT NULL,
  `PatientID` int(11) NOT NULL, `VisitID` int(11) DEFAULT NULL, `LaboratoryID` varchar(50) DEFAULT NULL,
  `PrescriptionReference` varchar(50) DEFAULT NULL, `PaymentType` enum('Consultation','Laboratory','Pharmacy') NOT NULL,
  `Amount` decimal(10,2) NOT NULL, `PaymentMethod` enum('Cash','Card','Mobile Money','Bank','Other') NOT NULL DEFAULT 'Cash',
  `PaymentStatus` enum('Confirmed','Voided') NOT NULL DEFAULT 'Confirmed', `ReceivedBy` int(11) DEFAULT NULL,
  `PaidAt` datetime NOT NULL DEFAULT current_timestamp(), `Notes` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`PaymentID`), UNIQUE KEY `uq_payments_reference` (`PaymentReference`),
  KEY `idx_payments_patient` (`PatientID`), KEY `idx_payments_visit` (`VisitID`), KEY `idx_payments_type_date` (`PaymentType`,`PaidAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `NotificationID` bigint(20) NOT NULL AUTO_INCREMENT, `UserID` int(11) DEFAULT NULL,
  `RoleTarget` enum('superuser','receptionuser','doctoruser','pharmacyuser','labuser') DEFAULT NULL,
  `EventType` varchar(50) NOT NULL, `Title` varchar(150) NOT NULL, `Message` varchar(500) NOT NULL,
  `Link` varchar(255) DEFAULT NULL, `IsRead` tinyint(1) NOT NULL DEFAULT 0, `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`NotificationID`), KEY `idx_notifications_user` (`UserID`,`IsRead`,`CreatedAt`), KEY `idx_notifications_role` (`RoleTarget`,`IsRead`,`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
