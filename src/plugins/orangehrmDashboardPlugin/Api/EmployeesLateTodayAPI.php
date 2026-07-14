<?php

/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with OrangeHRM.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace OrangeHRM\Dashboard\Api;

use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Exception\ForbiddenException;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\ResourceEndpoint;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Traits\Service\DateTimeHelperTrait;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Dashboard\Dao\AttendanceAnomalyDao;

/**
 * Experimental dashboard widget: "Retardos Hoy".
 * Employees late today under either of two fixed company-wide checkpoints
 * (morning entry after 09:06, or lunch return after 16:06 — see
 * AttendanceAnomalyDao::getLateEmployeesToday), excluding the excluded
 * departments (per explicit request).
 */
class EmployeesLateTodayAPI extends Endpoint implements ResourceEndpoint
{
    use DateTimeHelperTrait;
    use UserRoleManagerTrait;

    /**
     * Subunit ids excluded from this widget per explicit request:
     * - 20 "Recursos Humanos". Also covers "Nominas" and "Gestion de Talento" job
     *   titles, which are held exclusively by employees within this department.
     * - 73 "NO Recontratable" — this company doesn't use OrangeHRM's formal
     *   termination flow (employeeTerminationRecord/emp_status are unused), so
     *   departed employees are parked in this subunit instead. Excluding it keeps
     *   them out of "Retardos Hoy" even though they're technically still "active".
     */
    private const EXCLUDED_SUBUNIT_IDS = [20, 73];

    /**
     * Job title ids for IT/Systems roles (Seguridad Informatica, Asesor TI, BI,
     * Gerente Sistemas, Auxiliar Sistemas, Gerente de Sistemas). These employees
     * are scattered across multiple departments, so department exclusion alone
     * does not cover them. Excluded from this widget per explicit request.
     */
    private const EXCLUDED_JOB_TITLE_IDS = [1, 3, 4, 5, 33, 86];

    private ?AttendanceAnomalyDao $attendanceAnomalyDao = null;

    protected function getAttendanceAnomalyDao(): AttendanceAnomalyDao
    {
        if (!$this->attendanceAnomalyDao instanceof AttendanceAnomalyDao) {
            $this->attendanceAnomalyDao = new AttendanceAnomalyDao();
        }
        return $this->attendanceAnomalyDao;
    }

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResult
    {
        if (!$this->getUserRoleManager()->getDataGroupPermissions(
            'dashboard_late_widget',
            [],
            [],
            false
        )->canRead()) {
            throw new ForbiddenException();
        }

        $date = $this->getDateTimeHelper()->getNow();
        $employees = $this->getAttendanceAnomalyDao()->getLateEmployeesToday(
            $date,
            self::EXCLUDED_SUBUNIT_IDS,
            self::EXCLUDED_JOB_TITLE_IDS
        );

        return new EndpointResourceResult(ArrayModel::class, [
            'date' => $date->format('Y-m-d'),
            'employees' => $employees,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        $paramRules = new ParamRuleCollection();
        $paramRules->addExcludedParamKey(CommonParams::PARAMETER_ID);
        return $paramRules;
    }

    /**
     * @inheritDoc
     */
    public function update(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }
}
