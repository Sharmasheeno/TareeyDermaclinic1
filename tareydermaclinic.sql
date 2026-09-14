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
  `role` enum('superuser','receptionuser','pharmacyuser','doctor','labuser') NOT NULL,
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
(7, 'Dr. Abdalla Mohamed Hashi', 'doctor', 'Tareey', '$2y$10$XRlDCCSx9liqleo3vz0DuOu728wRKWmucbzQk.jVhnZuOyRIt0XnG', '2026-08-29 08:35:23', '2026-08-30 09:54:51');

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
