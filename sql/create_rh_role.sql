-- Crea el rol "RH" copiando los grants de Admin (role_id=1), restringido a
-- los modulos/pantallas acordados. No modifica el rol Admin ni ningun otro.
-- Idempotente: borra el rol RH si ya existia antes de recrearlo, para poder
-- re-correr este script sin duplicar filas.
--
-- Uso: docker exec mysql sh -c "mysql -uadmin -padmin123 <db> < /ruta/create_rh_role.sql"
-- (correr primero contra orangehrm_test, validar, luego contra orangehrm)
--
-- Recordatorio: ademas de este script, el dropdown de rol en
-- Admin > User Management > Users esta hardcodeado en el frontend
-- (src/client/src/orangehrmAdminPlugin/pages/systemUser/{SaveSystemUser,
-- EditSystemUser,SystemUser}.vue) -- si el id de rol cambia (por ejemplo
-- al recrear el rol en una base distinta donde el AUTO_INCREMENT difiera),
-- ese id hardcodeado (8) debe actualizarse ahi tambien.

SET @old_role_id = (SELECT id FROM ohrm_user_role WHERE name = 'RH');
DELETE FROM ohrm_user_role_screen WHERE user_role_id = @old_role_id;
DELETE FROM ohrm_user_role_data_group WHERE user_role_id = @old_role_id;
DELETE FROM ohrm_user_role WHERE name = 'RH';

INSERT INTO ohrm_user_role (name, display_name, is_assignable, is_predefined)
VALUES ('RH', 'RH', 1, 0);

SET @rh_role_id = LAST_INSERT_ID();

-- ============================================================
-- SCREENS: copiar de Admin (role_id=1), modulos incluidos, menos
-- las pantallas de Configuracion/Reportes/Eliminar excluidas.
-- Modulos incluidos: 3 pim, 4 leave, 5 time, 6 attendance,
-- 7 recruitment, 10 dashboard, 11 performance, 12 directory,
-- 15 buzz, 18 claim. (2 admin, 13 maintenance, 14 marketPlace: excluidos)
-- can_delete se fuerza a 0 en todos los casos (RH no elimina nada,
-- solo da de baja via Terminate Employment que es un screen aparte).
-- ============================================================
INSERT INTO ohrm_user_role_screen (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
SELECT @rh_role_id, urs.screen_id, urs.can_read, urs.can_create, urs.can_update, 0
FROM ohrm_user_role_screen urs
JOIN ohrm_screen s ON s.id = urs.screen_id
WHERE urs.user_role_id = 1
  AND s.module_id IN (3,4,5,6,7,10,11,12,15,18)
  AND urs.screen_id NOT IN (
    -- PIM: Delete Employees, Configure PIM, Custom Fields, Data Import,
    -- Reporting Method, Termination Reason, PIM Reports (list/define/display), My Info
    6, 40, 41, 42, 43, 44, 45, 46, 92, 93,
    -- Leave: Leave Type CRUD, Holiday CRUD, WorkWeek, Leave Period,
    -- Save/Add/Edit/Delete Leave Entitlements, Leave Balance Reports
    7, 8, 9, 10, 11, 12, 13, 14, 19, 47, 71, 72, 73, 78, 79, 144,
    -- Time: Customers, Projects, Timesheet Start Date, Report Criteria (x3),
    -- Add Customer, Save Project, Attendance Summary Report, Project Activity Report
    36, 37, 50, 57, 58, 59, 86, 88, 101, 102,
    -- Attendance: Attendance Configuration
    56,
    -- Recruitment: Delete Job Vacancy, Delete Candidate
    95, 97,
    -- Performance: Save KPI
    104,
    -- Claim: Events, Create Event, Expense Types, Create Expense (config)
    171, 172, 173, 174
  );

-- ============================================================
-- DATA GROUPS: copiar de Admin, filtrando por nombre.
-- Regla: incluir todo lo operativo de los modulos en scope,
-- excluir reportes, definicion de custom fields, importacion CSV,
-- y config (tipos de permiso, dias festivos, semana laboral,
-- periodo de permisos, configuracion de asistencia, clientes/
-- proyectos de Time, config de eventos/tipos de gasto de Claim).
-- can_delete se fuerza a 0 en todos los casos.
-- ============================================================
INSERT INTO ohrm_user_role_data_group (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, self)
SELECT @rh_role_id, urdg.data_group_id, urdg.can_read, urdg.can_create, urdg.can_update, 0, urdg.self
FROM ohrm_user_role_data_group urdg
JOIN ohrm_data_group dg ON dg.id = urdg.data_group_id
WHERE urdg.user_role_id = 1
  AND (
    dg.name REGEXP '^(personal_|contact_|emergency_|dependents|immigration|job_details|job_attachment|job_custom_fields|salary_|tax_|supervisor$|subordinates$|report-to_|qualification|membership|photograph)'
    OR dg.name REGEXP '^apiv2_pim_'
    OR dg.name REGEXP '^(leave_|apiv2_leave_)'
    OR dg.name IN ('attendance_records', 'attendance_summary')
    OR dg.name REGEXP '^apiv2_attendance_'
    OR dg.name = 'time_employee_timesheets'
    OR dg.name REGEXP '^apiv2_time_(my_timesheet|employees_timesheets|timesheet)'
    OR dg.name REGEXP '^(recruitment_|apiv2_recruitment_)'
    OR dg.name REGEXP '^apiv2_performance_'
    OR dg.name = 'performance_tracker_log'
    OR dg.name REGEXP '^(buzz_|apiv2_buzz_)'
    OR dg.name = 'apiv2_corporate_directory_employees'
    OR dg.name REGEXP '^apiv2_claim_'
    OR dg.name REGEXP '^(dashboard_|apiv2_dashboard_)'
    OR dg.name IN (
      -- listas de referencia necesarias para los dropdowns de las
      -- pestanas de PIM (puesto, salario, ubicacion, etc.) -- solo
      -- lectura, no gestion de estas listas maestras
      'job_titles', 'pay_grades', 'locations',
      'apiv2_admin_job_title', 'apiv2_admin_pay_grade', 'apiv2_admin_location',
      'apiv2_admin_employment_status', 'apiv2_admin_job_category',
      'apiv2_admin_nationality', 'apiv2_admin_education', 'apiv2_admin_skill',
      'apiv2_admin_license', 'apiv2_admin_language', 'apiv2_admin_membership',
      'apiv2_admin_subunit', 'apiv2_admin_paygrade_currency',
      'apiv2_admin_paygrade_allowed_currency', 'apiv2_admin_work_shift',
      'apiv2_admin_work_shift_employee'
    )
    OR dg.name IN ('apiv2_core_validate_uniqueness', 'apiv2_core_data_groups', 'apiv2_core_about_organization')
  )
  AND dg.name NOT IN (
    'pim_reports', 'apiv2_pim_reports', 'apiv2_pim_reports_data', 'apiv2_pim_defined_reports',
    'apiv2_pim_custom_field', 'apiv2_pim_optional_field', 'apiv2_pim_reporting_method',
    'apiv2_pim_termination_reason', 'apiv2_pim_employee_csv_import',
    'leave_period', 'leave_types', 'work_week', 'holidays',
    'apiv2_leave_leave_types', 'apiv2_leave_leave_period', 'apiv2_leave_holiday',
    'apiv2_leave_workweek', 'apiv2_leave_reports', 'apiv2_leave_reports_data',
    'leave_report_employee_leave_entitlements_and_usage',
    'leave_report_leave_type_leave_entitlements_and_usage',
    'leave_report_my_leave_entitlements_and_usage',
    'attendance_configuration', 'apiv2_attendance_configuration',
    'time_project_reports', 'time_employee_reports', 'apiv2_time_reports', 'apiv2_time_reports_data'
  );

-- Fuerza can_delete=0 tambien para las filas con "self" (edicion de datos
-- propios), por si Admin las tuviera con delete=1.
UPDATE ohrm_user_role_data_group SET can_delete = 0 WHERE user_role_id = @rh_role_id;

-- Para las listas de referencia (job_titles, pay_grades, locations, etc.)
-- limitar a solo lectura: RH no debe poder crear/editar/borrar esas listas
-- maestras, solo leerlas para poblar los dropdowns de PIM.
UPDATE ohrm_user_role_data_group
SET can_create = 0, can_update = 0
WHERE user_role_id = @rh_role_id
  AND data_group_id IN (
    SELECT id FROM ohrm_data_group WHERE name IN (
      'job_titles', 'pay_grades', 'locations',
      'apiv2_admin_job_title', 'apiv2_admin_pay_grade', 'apiv2_admin_location',
      'apiv2_admin_employment_status', 'apiv2_admin_job_category',
      'apiv2_admin_nationality', 'apiv2_admin_education', 'apiv2_admin_skill',
      'apiv2_admin_license', 'apiv2_admin_language', 'apiv2_admin_membership',
      'apiv2_admin_subunit', 'apiv2_admin_paygrade_currency',
      'apiv2_admin_paygrade_allowed_currency', 'apiv2_admin_work_shift',
      'apiv2_admin_work_shift_employee'
    )
  );

SELECT @rh_role_id AS rh_role_id,
  (SELECT COUNT(*) FROM ohrm_user_role_screen WHERE user_role_id = @rh_role_id) AS screen_grants,
  (SELECT COUNT(*) FROM ohrm_user_role_data_group WHERE user_role_id = @rh_role_id) AS datagroup_grants;
