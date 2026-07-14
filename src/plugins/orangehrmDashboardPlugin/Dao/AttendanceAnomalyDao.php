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

namespace OrangeHRM\Dashboard\Dao;

use DateTime;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use OrangeHRM\Core\Dao\BaseDao;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\Leave;
use OrangeHRM\ORM\ListSorter;

/**
 * Experimental: dashboard widgets "Faltas Hoy" and "Retardos Hoy".
 * Independent from existing dashboard widgets (EmployeeOnLeaveDao, etc.), which are untouched.
 */
class AttendanceAnomalyDao extends BaseDao
{
    /**
     * Employees with no punch-in record today, excluding those on approved/pending/taken
     * leave today and, optionally, a set of departments (subunits) and/or job titles.
     *
     * @param DateTime $date
     * @param int[] $excludeSubunitIds
     * @param int[] $excludeJobTitleIds
     * @return array
     */
    public function getAbsentEmployeesToday(
        DateTime $date,
        array $excludeSubunitIds = [],
        array $excludeJobTitleIds = []
    ): array {
        $onLeaveEmpNumbers = $this->getEmpNumbersOnLeave($date);

        $q = $this->createQueryBuilder(Employee::class, 'employee');
        $q->leftJoin('employee.subDivision', 'subunit');
        $q->leftJoin('employee.jobTitle', 'jobTitle');
        $q->leftJoin('employee.attendanceRecords', 'attendanceRecord', Expr\Join::WITH, $q->expr()->andX(
            $q->expr()->gte('attendanceRecord.punchInUserTime', ':fromDate'),
            $q->expr()->lte('attendanceRecord.punchInUserTime', ':toDate')
        ));
        $q->select(
            'employee.empNumber AS empNumber',
            'employee.employeeId AS employeeId',
            "CONCAT(employee.firstName, ' ', employee.lastName) AS employeeName",
            'subunit.name AS department'
        );
        $q->andWhere($q->expr()->isNull('attendanceRecord.id'));
        $q->andWhere($q->expr()->isNull('employee.purgedAt'));
        $q->andWhere($q->expr()->isNull('employee.employeeTerminationRecord'));
        $q->setParameter('fromDate', $date->format('Y-m-d') . ' 00:00:00');
        $q->setParameter('toDate', $date->format('Y-m-d') . ' 23:59:59');

        if (!empty($onLeaveEmpNumbers)) {
            $q->andWhere($q->expr()->notIn('employee.empNumber', ':onLeave'))
                ->setParameter('onLeave', $onLeaveEmpNumbers);
        }

        $this->applySubunitExclusion($q, $excludeSubunitIds);
        $this->applyJobTitleExclusion($q, $excludeJobTitleIds);

        $q->orderBy('employee.empNumber', ListSorter::ASCENDING);

        return $q->getQuery()->execute();
    }

    /**
     * Employees whose first punch-in today is later than their configured work shift
     * start time (falls back to $defaultExpectedStartTime when no shift is assigned),
     * optionally excluding a set of departments (subunits).
     *
     * @param DateTime $date
     * @param string $defaultExpectedStartTime H:i:s
     * @param int[] $excludeSubunitIds
     * @param int[] $excludeJobTitleIds
     * @return array
     */
    public function getLateEmployeesToday(
        DateTime $date,
        string $defaultExpectedStartTime,
        array $excludeSubunitIds = [],
        array $excludeJobTitleIds = []
    ): array {
        $q = $this->createQueryBuilder(Employee::class, 'employee');
        $q->leftJoin('employee.subDivision', 'subunit');
        $q->leftJoin('employee.jobTitle', 'jobTitle');
        $q->leftJoin('employee.employeeWorkShift', 'empWorkShift');
        $q->leftJoin('empWorkShift.workShift', 'workShift');
        $q->leftJoin('employee.attendanceRecords', 'attendanceRecord', Expr\Join::WITH, $q->expr()->andX(
            $q->expr()->gte('attendanceRecord.punchInUserTime', ':fromDate'),
            $q->expr()->lte('attendanceRecord.punchInUserTime', ':toDate')
        ));
        $q->select(
            'employee.empNumber AS empNumber',
            'employee.employeeId AS employeeId',
            "CONCAT(employee.firstName, ' ', employee.lastName) AS employeeName",
            'subunit.name AS department',
            'MIN(attendanceRecord.punchInUserTime) AS firstPunchIn',
            'MIN(workShift.startTime) AS shiftStartTime'
        );
        $q->andWhere($q->expr()->isNotNull('attendanceRecord.id'));
        $q->andWhere($q->expr()->isNull('employee.purgedAt'));
        $q->andWhere($q->expr()->isNull('employee.employeeTerminationRecord'));
        $q->setParameter('fromDate', $date->format('Y-m-d') . ' 00:00:00');
        $q->setParameter('toDate', $date->format('Y-m-d') . ' 23:59:59');

        $this->applySubunitExclusion($q, $excludeSubunitIds);
        $this->applyJobTitleExclusion($q, $excludeJobTitleIds);

        $q->groupBy('employee.empNumber');
        $q->orderBy('employee.empNumber', ListSorter::ASCENDING);

        $rows = $q->getQuery()->execute();

        $result = [];
        foreach ($rows as $row) {
            if ($row['firstPunchIn'] === null) {
                continue;
            }
            // MIN() aggregates lose their Doctrine column type on hydration and
            // come back as plain strings instead of DateTime instances.
            $firstPunchIn = $row['firstPunchIn'] instanceof DateTime
                ? $row['firstPunchIn']
                : new DateTime($row['firstPunchIn']);
            $shiftStartTime = $row['shiftStartTime'];
            if ($shiftStartTime !== null && !($shiftStartTime instanceof DateTime)) {
                $shiftStartTime = new DateTime($shiftStartTime);
            }
            $expectedStartTime = $shiftStartTime instanceof DateTime
                ? $shiftStartTime->format('H:i:s')
                : $defaultExpectedStartTime;

            if ($firstPunchIn->format('H:i:s') > $expectedStartTime) {
                $result[] = [
                    'empNumber' => $row['empNumber'],
                    'employeeId' => $row['employeeId'],
                    'employeeName' => $row['employeeName'],
                    'department' => $row['department'],
                    'expectedStartTime' => substr($expectedStartTime, 0, 5),
                    'actualPunchInTime' => $firstPunchIn->format('H:i'),
                ];
            }
        }
        return $result;
    }

    /**
     * @param DateTime $date
     * @return int[]
     */
    private function getEmpNumbersOnLeave(DateTime $date): array
    {
        $q = $this->createQueryBuilder(Leave::class, 'leaveRec');
        $q->select('IDENTITY(leaveRec.employee) AS empNumber');
        $q->leftJoin('leaveRec.leaveType', 'leaveType');
        $q->andWhere('leaveRec.date = :date')->setParameter('date', $date->format('Y-m-d'));
        $q->andWhere('leaveType.deleted = :deleted')->setParameter('deleted', false);
        $q->andWhere($q->expr()->in('leaveRec.status', ':statuses'))
            ->setParameter('statuses', [
                Leave::LEAVE_STATUS_LEAVE_PENDING_APPROVAL,
                Leave::LEAVE_STATUS_LEAVE_APPROVED,
                Leave::LEAVE_STATUS_LEAVE_TAKEN,
            ]);
        return array_column($q->getQuery()->execute(), 'empNumber');
    }

    /**
     * @param QueryBuilder $q
     * @param int[] $excludeSubunitIds
     */
    private function applySubunitExclusion(QueryBuilder $q, array $excludeSubunitIds): void
    {
        if (empty($excludeSubunitIds)) {
            return;
        }
        $q->andWhere($q->expr()->orX(
            $q->expr()->isNull('subunit.id'),
            $q->expr()->notIn('subunit.id', ':excludeSubunits')
        ))->setParameter('excludeSubunits', $excludeSubunitIds);
    }

    /**
     * @param QueryBuilder $q
     * @param int[] $excludeJobTitleIds
     */
    private function applyJobTitleExclusion(QueryBuilder $q, array $excludeJobTitleIds): void
    {
        if (empty($excludeJobTitleIds)) {
            return;
        }
        $q->andWhere($q->expr()->orX(
            $q->expr()->isNull('jobTitle.id'),
            $q->expr()->notIn('jobTitle.id', ':excludeJobTitles')
        ))->setParameter('excludeJobTitles', $excludeJobTitleIds);
    }
}
