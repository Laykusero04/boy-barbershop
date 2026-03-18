-- Data Integrity: Foreign key constraints for Boy Barbershop
-- Run once (e.g. in phpMyAdmin or: mysql -u root boy_barbershop < data_integrity_foreign_keys.sql)
-- Ensures: barbers/services used in sales cannot be hard-deleted (ON DELETE RESTRICT).

-- Use InnoDB for FK support (if tables were created as MyISAM)
ALTER TABLE barbers   ENGINE=InnoDB;
ALTER TABLE services  ENGINE=InnoDB;
ALTER TABLE sales     ENGINE=InnoDB;

-- Sales must reference existing barber and service (prevents deleting barbers/services that have sales)
ALTER TABLE sales
  ADD CONSTRAINT fk_sales_barber  FOREIGN KEY (barber_id)  REFERENCES barbers(id)  ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_sales_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT ON UPDATE CASCADE;
