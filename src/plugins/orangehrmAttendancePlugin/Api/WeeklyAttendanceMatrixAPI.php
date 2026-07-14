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

namespace OrangeHRM\Attendance\Api;

use OrangeHRM\Attendance\Dao\AttendanceDao;
use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Exception\ForbiddenException;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\ResourceEndpoint;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\Location;
use OrangeHRM\Entity\Subunit;

/**
 * Experimental: employee x day attendance matrix for a date range.
 * Independent from the existing "Informe de asistencias totales"
 * (AttendanceReport / AttendanceSummaryReport.vue), which is untouched.
 *
 * Reuses the same 'attendance_summary' data group permission as the
 * existing AttendanceReport, since it exposes the same underlying data.
 */
class WeeklyAttendanceMatrixAPI extends Endpoint implements ResourceEndpoint
{
    use UserRoleManagerTrait;

    public const FILTER_FROM_DATE = 'fromDate';
    public const FILTER_TO_DATE = 'toDate';
    public const FILTER_LOCATION_ID = 'locationId';
    public const FILTER_SUBUNIT_ID = 'subunitId';
    public const FILTER_EMP_NUMBER = CommonParams::PARAMETER_EMP_NUMBER;

    /**
     * Subunit ids excluded from this matrix per explicit request, same
     * "Sistemas" IT/Systems departments excluded elsewhere (see
     * EmployeesAbsentTodayAPI/EmployeesLateTodayAPI): "Sistemas Matriz" (29),
     * "Sistemas Staff" (30), "Sistemas_sur" (58).
     */
    private const EXCLUDED_SUBUNIT_IDS = [29, 30, 58];

    private ?AttendanceDao $attendanceDao = null;

    protected function getAttendanceDao(): AttendanceDao
    {
        if (!$this->attendanceDao instanceof AttendanceDao) {
            $this->attendanceDao = new AttendanceDao();
        }
        return $this->attendanceDao;
    }

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResult
    {
        if (!$this->getUserRoleManager()->getDataGroupPermissions(
            'attendance_summary',
            [],
            [],
            false
        )->canRead()) {
            throw new ForbiddenException();
        }

        $fromDate = $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_FROM_DATE
        );
        $toDate = $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_TO_DATE
        );
        $locationId = $this->getRequestParams()->getIntOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_LOCATION_ID
        );
        $subunitId = $this->getRequestParams()->getIntOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_SUBUNIT_ID
        );
        $empNumber = $this->getRequestParams()->getIntOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_EMP_NUMBER
        );

        $matrix = $this->getAttendanceDao()->getWeeklyAttendanceMatrix(
            $fromDate,
            $toDate,
            $locationId,
            $subunitId,
            $empNumber,
            self::EXCLUDED_SUBUNIT_IDS
        );

        return new EndpointResourceResult(ArrayModel::class, [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'employees' => $matrix,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        $paramRules = new ParamRuleCollection(
            new ParamRule(self::FILTER_FROM_DATE, new Rule(Rules::API_DATE)),
            new ParamRule(self::FILTER_TO_DATE, new Rule(Rules::API_DATE)),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_LOCATION_ID,
                    new Rule(Rules::POSITIVE),
                    new Rule(Rules::ENTITY_ID_EXISTS, [Location::class])
                )
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_SUBUNIT_ID,
                    new Rule(Rules::POSITIVE),
                    new Rule(Rules::ENTITY_ID_EXISTS, [Subunit::class])
                )
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_EMP_NUMBER,
                    new Rule(Rules::POSITIVE),
                    new Rule(Rules::ENTITY_ID_EXISTS, [Employee::class])
                )
            ),
        );
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
