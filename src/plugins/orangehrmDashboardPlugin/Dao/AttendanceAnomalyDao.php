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
     * Employee id prefix used for managers/gerentes (e.g. "G001"). Managers are
     * excluded from the "Faltas Hoy" widget per explicit request, since they don't
     * follow the regular punch-in schedule.
     */
    private const MANAGER_EMPLOYEE_ID_PATTERN = 'G%';

    /**
     * Employees with no punch-in record today, excluding those on approved/pending/taken
     * leave today and, optionally, a set of departments (subunits), job titles, and
     * managers (employee id starting with "G").
     *
     * @param DateTime $date
     * @param int[] $excludeSubunitIds
     * @param int[] $excludeJobTitleIds
     * @param bool $excludeManagerEmployeeIds
     * @return array
     */
    public function getAbsentEmployeesToday(
        DateTime $date,
        array $excludeSubunitIds = [],
        array $excludeJobTitleIds = [],
        bool $excludeManagerEmployeeIds = false
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

        if ($excludeManagerEmployeeIds) {
            $q->andWhere($q->expr()->notLike('employee.employeeId', ':managerIdPattern'))
                ->setParameter('managerIdPattern', self::MANAGER_EMPLOYEE_ID_PATTERN);
        }

        $this->applySubunitExclusion($q, $excludeSubunitIds);
        $this->applyJobTitleExclusion($q, $excludeJobTitleIds);

        // No punch-in exists for these employees, so there's no arrival time to sort
        // by; order by name instead so the list is still easy to scan.
        $q->orderBy('employee.firstName', ListSorter::ASCENDING);
        $q->addOrderBy('employee.lastName', ListSorter::ASCENDING);

        return $q->getQuery()->execute();
    }

    /**
     * Fixed company-wide checkpoints for the "Retardos Hoy" widget. Replaces the
     * previous per-employee work-shift lookup entirely, per explicit request:
     * every employee is judged against these same two checkpoints regardless of
     * whether they have a shift configured.
     */
    private const LATE_ENTRY_THRESHOLD = '09:06';
    private const LATE_LUNCH_RETURN_THRESHOLD = '16:06';

    /**
     * Employees late today under either of two checkpoints, optionally excluding a
     * set of departments (subunits) and/or job titles:
     * - Morning entry (day's first punch-in) after 09:06.
     * - Lunch return (day's second punch-in, i.e. the one following the lunch
     *   punch-out) after 16:06.
     * An employee can trigger one or both; each row lists every incident that
     * applied. Employees with only one punch-in today are only checked against the
     * morning-entry threshold, since they have no lunch-return punch yet/at all.
     *
     * @param DateTime $date
     * @param int[] $excludeSubunitIds
     * @param int[] $excludeJobTitleIds
     * @return array
     */
    public function getLateEmployeesToday(
        DateTime $date,
        array $excludeSubunitIds = [],
        array $excludeJobTitleIds = []
    ): array {
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
            'subunit.name AS department',
            'attendanceRecord.punchInUserTime AS punchInTime'
        );
        $q->andWhere($q->expr()->isNotNull('attendanceRecord.id'));
        $q->andWhere($q->expr()->isNull('employee.purgedAt'));
        $q->andWhere($q->expr()->isNull('employee.employeeTerminationRecord'));
        $q->setParameter('fromDate', $date->format('Y-m-d') . ' 00:00:00');
        $q->setParameter('toDate', $date->format('Y-m-d') . ' 23:59:59');

        $this->applySubunitExclusion($q, $excludeSubunitIds);
        $this->applyJobTitleExclusion($q, $excludeJobTitleIds);

        $q->orderBy('employee.empNumber', ListSorter::ASCENDING);
        $q->addOrderBy('attendanceRecord.punchInUserTime', ListSorter::ASCENDING);

        $rows = $q->getQuery()->execute();

        $employees = [];
        foreach ($rows as $row) {
            $empNumber = $row['empNumber'];
            if (!isset($employees[$empNumber])) {
                $employees[$empNumber] = [
                    'empNumber' => $empNumber,
                    'employeeId' => $row['employeeId'],
                    'employeeName' => $row['employeeName'],
                    'department' => $row['department'],
                    'punchIns' => [],
                ];
            }
            if ($row['punchInTime'] !== null) {
                $employees[$empNumber]['punchIns'][] = $row['punchInTime'];
            }
        }

        $result = [];
        foreach ($employees as $data) {
            /** @var DateTime[] $punchIns */
            $punchIns = $data['punchIns'];
            if (empty($punchIns)) {
                continue;
            }

            $incidents = [];

            // Compared at minute granularity (not H:i:s) so that, e.g., a punch-in
            // at 09:06:03 still displays as "on time" instead of showing the same
            // "09:06" as both actual and expected while being flagged as late.
            $morningPunchIn = $punchIns[0];
            if ($morningPunchIn->format('H:i') > self::LATE_ENTRY_THRESHOLD) {
                $incidents[] = [
                    'type' => 'entrada',
                    'label' => 'Entrada',
                    'expected' => self::LATE_ENTRY_THRESHOLD,
                    'actual' => $morningPunchIn->format('H:i'),
                ];
            }

            if (isset($punchIns[1])) {
                $lunchReturn = $punchIns[1];
                if ($lunchReturn->format('H:i') > self::LATE_LUNCH_RETURN_THRESHOLD) {
                    $incidents[] = [
                        'type' => 'comida',
                        'label' => 'Regreso de comida',
                        'expected' => self::LATE_LUNCH_RETURN_THRESHOLD,
                        'actual' => $lunchReturn->format('H:i'),
                    ];
                }
            }

            if (empty($incidents)) {
                continue;
            }

            $result[] = [
                'empNumber' => $data['empNumber'],
                'employeeId' => $data['employeeId'],
                'employeeName' => $data['employeeName'],
                'department' => $data['department'],
                'incidents' => $incidents,
                'firstPunchIn' => $morningPunchIn->format('H:i'),
            ];
        }

        usort($result, fn (array $a, array $b) => $a['firstPunchIn'] <=> $b['firstPunchIn']);

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
