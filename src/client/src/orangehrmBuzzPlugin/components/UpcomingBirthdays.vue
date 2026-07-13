<!--
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
 -->

<template>
  <div class="orangehrm-buzz-birthday">
    <oxd-text type="card-title" class="orangehrm-buzz-birthday-title">
      {{ $t('buzz.upcoming_birthdays') }}
    </oxd-text>
    <div
      class="orangehrm-buzz-birthday-content"
      :class="{'--show-more': birthdaysCount > 5}"
    >
      <div
        v-for="birthday in birthdays"
        :key="birthday"
        class="orangehrm-buzz-birthday-item"
      >
        <div class="orangehrm-buzz-birthday-profile">
          <profile-image :employee="birthday"></profile-image>
          <div class="orangehrm-buzz-birthday-profile-details">
            <oxd-text tag="p" class="orangehrm-buzz-birthday-emp-name">
              {{ birthday.empName }}
            </oxd-text>
            <oxd-text tag="p" class="orangehrm-buzz-birthday-job-details">
              {{ birthday.jobTitle }}
            </oxd-text>
            <oxd-text
              v-if="birthday.location"
              tag="p"
              class="orangehrm-buzz-birthday-location"
            >
              {{ birthday.location }}
            </oxd-text>
          </div>
        </div>
        <div class="orangehrm-buzz-birthday-duration">
          <oxd-icon
            class="orangehrm-buzz-birthday-year-celebration"
            name="balloon-fill"
          ></oxd-icon>
          <div class="orangehrm-buzz-birthday-durations-text">
            <oxd-text tag="p" class="orangehrm-buzz-birthday-duration-date">
              {{ birthday.birthdayDate }}
            </oxd-text>
          </div>
        </div>
      </div>
      <div v-if="isEmpty" class="orangehrm-buzz-birthday-nocontent">
        <img :src="noContentPic" alt="No Content" />
        <oxd-text tag="p">
          {{ $t('general.no_records_found') }}
        </oxd-text>
      </div>
    </div>
    <div v-if="birthdaysCount > 5" class="orangehrm-buzz-birthday-footer">
      <oxd-text tag="p" @click="onSeeMore">
        {{ isViewDetails ? $t('general.show_more') : $t('general.show_less') }}
      </oxd-text>
    </div>
  </div>
</template>

<script>
import {OxdIcon} from '@ohrm/oxd';
import useLocale from '@/core/util/composable/useLocale';
import {APIService} from '@/core/util/services/api.service';
import {parseDate, formatDate} from '@/core/util/helper/datefns';
import ProfileImage from '@/orangehrmBuzzPlugin/components/ProfileImage';
import useEmployeeNameTranslate from '@/core/util/composable/useEmployeeNameTranslate';

export default {
  name: 'UpcomingBirthdays',

  components: {
    'profile-image': ProfileImage,
    'oxd-icon': OxdIcon,
  },

  setup() {
    const {locale} = useLocale();
    const {$tEmpName} = useEmployeeNameTranslate();
    const noContentPic = `${window.appGlobal.publicPath}/images/buzz_no_anniversaries.png`;

    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/buzz/birthdays',
    );

    return {
      http,
      locale,
      noContentPic,
      tEmpName: $tEmpName,
    };
  },

  data() {
    return {
      viewMore: false,
      isLoading: false,
      birthdays: [],
      birthdaysCount: 0,
    };
  },

  computed: {
    isViewDetails() {
      return !this.viewMore;
    },
    isEmpty() {
      return !this.isLoading && this.birthdays.length === 0;
    },
  },

  beforeMount() {
    this.birthdaysLimit = 5;
    this.getBirthdays();
  },

  methods: {
    onSeeMore() {
      this.viewMore = !this.viewMore;
      if (this.viewMore) {
        this.birthdaysLimit = 0;
      } else {
        this.birthdaysLimit = 5;
      }
      this.getBirthdays();
    },
    getBirthdays() {
      this.isLoading = true;
      this.http
        .getAll({limit: this.birthdaysLimit})
        .then((response) => {
          const {data, meta} = response.data;
          this.birthdays = data.map((item) => {
            const {employee, jobTitle, location, birthday} = item;
            return {
              empNumber: employee.empNumber,
              empName: this.tEmpName(employee, {
                includeMiddle: false,
                excludePastEmpTag: false,
              }),
              jobTitle: jobTitle.title,
              location: location?.name,
              birthdayDate: formatDate(parseDate(birthday), 'MMM dd', {
                locale: this.locale,
              }),
            };
          });
          this.birthdaysCount = meta?.total;
        })
        .finally(() => (this.isLoading = false));
    },
  },
};
</script>

<style src="./upcoming-birthdays.scss" lang="scss" scoped></style>
