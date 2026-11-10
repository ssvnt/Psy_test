<script setup>
import { RouterLink } from 'vue-router';
import DzShell from '../components/landing/DzShell.vue';

defineProps({ example: { type: Object, required: true } });
</script>

<template>
  <DzShell compact design="report-example" class="example">
    <template #compact-label>{{ example.label }}</template>
    <div class="artwork" aria-hidden="true"><i v-for="n in 24" :key="n" /></div>
    <section class="dz-section">
      <h1 class="dz-title">{{ example.title[0] }}<br>{{ example.title[1] }}</h1>
      <div class="example-hero">
        <img :src="example.image" alt="" width="640" height="520">
        <div class="dz-stack">
          <p class="dz-small">Два примера отчета</p>
          <p>{{ example.intro }}</p>
          <div class="report-choices">
            <a href="#free-report" class="dz-button">Бесплатный отчет</a>
            <RouterLink :to="example.full" class="dz-button">Платный отчет</RouterLink>
          </div>
        </div>
      </div>
    </section>
    <section id="free-report" tabindex="-1" aria-label="Два наблюдения" class="dz-columns dz-free-cards">
      <article v-for="card in example.examples" :key="card.title" class="dz-card">
        <h2 class="dz-caption">{{ card.title }}</h2>
        <p class="dz-pill">{{ card.pill }}</p>
        <p>{{ card.text }}</p>
      </article>
    </section>
    <section class="dz-note">
      <h2 class="dz-pill">{{ example.activity.title }}</h2>
      <p>{{ example.activity.text }}</p>
      <p class="dz-small">{{ example.activity.note }}</p>
    </section>
    <section id="report-offer" class="dz-card dz-offer">
      <h2 class="dz-heading">{{ example.offerTitle[0] }}<br>{{ example.offerTitle[1] }}</h2>
      <p>{{ example.offerText }}</p>
      <div class="offer-grid">
        <div class="dz-stack">
          <div v-for="item in example.benefits" :key="item.title">
            <h3 class="dz-pill">{{ item.title }}</h3><p>{{ item.text }}</p>
          </div>
        </div>
        <aside class="dz-checkout dz-stack">
          <h3 class="dz-pill">{{ example.product }}</h3>
          <p class="dz-price">{{ example.price }}</p>
          <p>Отчёт, PDF и консультация.<br>Разовая оплата, без подписки.</p>
          <RouterLink class="dz-button" :to="example.test">Пройти тест</RouterLink>
          <p class="dz-small">Это пример. Оплатить свой отчёт и применить промокод можно после теста.</p>
        </aside>
        <div><h3 class="dz-pill">{{ example.consultation.title }}</h3><p>{{ example.consultation.text }}</p></div>
        <p class="right">Онлайн. Запись после оплаты.</p>
      </div>
    </section>
    <section class="dz-columns" aria-label="Вопросы родителей">
      <div v-for="item in example.questions" :key="item.title">
        <h2 class="dz-pill">{{ item.title }}</h2><p>{{ item.text }}</p>
      </div>
    </section>
    <section class="dz-note">
      <h2 class="dz-caption">Что дает платный отчет?</h2><p>{{ example.comparison }}</p>
    </section>
  </DzShell>
</template>

<style scoped>
.report-choices{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:12px}.report-choices .dz-button{width:100%;height:100%;text-align:center}.report-choices a:focus-visible{outline:3px solid #32533d;outline-offset:3px}#free-report{scroll-margin-top:24px}@media(max-width:700px){.report-choices{grid-template-columns:1fr}}
.example :deep(.dz-container){position:static}
.example :deep(.dz-header){justify-content:flex-start;align-items:flex-start}
.artwork{position:absolute;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
.artwork i{position:absolute;background:url('/images/dz-teen-v1/logo.png') center/contain no-repeat}
.example-hero,.offer-grid{display:grid;grid-template-columns:minmax(0,640fr) minmax(0,720fr);gap:48px;align-items:center}
.example-hero img{width:100%;height:520px;object-fit:contain}
.offer-grid{grid-template-columns:minmax(0,744fr) minmax(0,536fr);align-items:start;gap:32px}
.offer-grid .dz-pill,.dz-columns>div .dz-pill{width:fit-content;max-width:100%;margin:0 auto 16px}
.right{text-align:right}.dz-heading,.dz-title{background:linear-gradient(95deg,#73ab7b,#c98d52);background-clip:text;color:transparent}
.dz-price{color:#527d59}#report-offer{scroll-margin-top:1rem}
@media(max-width:700px){.example-hero,.offer-grid,.example .dz-columns{grid-template-columns:minmax(0,1fr);gap:16px}.example-hero img{order:2;height:220px}.example :deep(.dz-header){flex-direction:column;gap:12px}.example :deep(.dz-header .dz-small){flex-basis:auto}.example .dz-offer,.example .dz-note{padding:20px}.example .dz-checkout{padding:16px}.right{text-align:left}}
@media (min-width:701px) {
.artwork i:nth-child(1) {left:7.600%;top:34.126%;width:6.068%;aspect-ratio:104.863/128;opacity:0.18;transform:translate(-50%,-50%) rotate(-5.24deg)}
.artwork i:nth-child(2) {left:95.694%;top:69.900%;width:11.378%;aspect-ratio:196.618/240;opacity:0.18;transform:translate(-50%,-50%) rotate(-20.33deg)}
.artwork i:nth-child(3) {left:38.793%;top:37.004%;width:4.172%;aspect-ratio:72.093/88;opacity:0.18;transform:translate(-50%,-50%) rotate(19.09deg)}
.artwork i:nth-child(4) {left:46.298%;top:76.234%;width:8.344%;aspect-ratio:144.187/176;opacity:0.18;transform:translate(-50%,-50%) rotate(-13.12deg)}
.artwork i:nth-child(5) {left:28.852%;top:26.376%;width:3.034%;aspect-ratio:52.431/64;opacity:0.18;transform:translate(-50%,-50%) rotate(-6.56deg)}
.artwork i:nth-child(6) {left:4.425%;top:55.552%;width:9.482%;aspect-ratio:163.848/200;opacity:0.18;transform:translate(-50%,-50%) rotate(8.73deg)}
.artwork i:nth-child(7) {left:94.922%;top:79.824%;width:1.707%;aspect-ratio:29.493/36;opacity:0.18;transform:translate(-50%,-50%) rotate(-12.56deg)}
.artwork i:nth-child(8) {left:11.133%;top:43.840%;width:2.276%;aspect-ratio:39.324/48;opacity:0.18;transform:translate(-50%,-50%) rotate(15.9deg)}
.artwork i:nth-child(9) {left:72.043%;top:53.445%;width:6.068%;aspect-ratio:104.863/128;opacity:0.18;transform:translate(-50%,-50%) rotate(22.71deg)}
.artwork i:nth-child(10) {left:52.896%;top:62.365%;width:11.378%;aspect-ratio:196.618/240;opacity:0.18;transform:translate(-50%,-50%) rotate(-9.03deg)}
.artwork i:nth-child(11) {left:4.949%;top:28.236%;width:4.172%;aspect-ratio:72.093/88;opacity:0.18;transform:translate(-50%,-50%) rotate(24.41deg)}
.artwork i:nth-child(12) {left:93.752%;top:37.748%;width:8.344%;aspect-ratio:144.187/176;opacity:0.18;transform:translate(-50%,-50%) rotate(1.95deg)}
.artwork i:nth-child(13) {left:4.852%;top:71.226%;width:9.482%;aspect-ratio:163.848/200;opacity:0.18;transform:translate(-50%,-50%) rotate(1.95deg)}
.artwork i:nth-child(14) {left:9.384%;top:76.991%;width:3.034%;aspect-ratio:52.431/64;opacity:0.18;transform:translate(-50%,-50%) rotate(-6.14deg)}
.artwork i:nth-child(15) {left:23.894%;top:58.693%;width:2.276%;aspect-ratio:39.324/48;opacity:0.18;transform:translate(-50%,-50%) rotate(7.01deg)}
.artwork i:nth-child(16) {left:2.316%;top:88.208%;width:1.707%;aspect-ratio:29.493/36;opacity:0.18;transform:translate(-50%,-50%) rotate(-19.14deg)}
.artwork i:nth-child(17) {left:97.001%;top:59.350%;width:4.172%;aspect-ratio:72.093/88;opacity:0.18;transform:translate(-50%,-50%) rotate(1.53deg)}
.artwork i:nth-child(18) {left:55.466%;top:48.366%;width:11.378%;aspect-ratio:196.618/240;opacity:0.18;transform:translate(-50%,-50%) rotate(12.02deg)}
.artwork i:nth-child(19) {left:27.310%;top:13.091%;width:3.034%;aspect-ratio:52.431/64;opacity:0.18;transform:translate(-50%,-50%) rotate(28.17deg)}
.artwork i:nth-child(20) {left:19.378%;top:67.315%;width:2.276%;aspect-ratio:39.324/48;opacity:0.18;transform:translate(-50%,-50%) rotate(14.77deg)}
.artwork i:nth-child(21) {left:3.938%;top:11.703%;width:6.068%;aspect-ratio:104.863/128;opacity:0.18;transform:translate(-50%,-50%) rotate(5.94deg)}
.artwork i:nth-child(22) {left:95.029%;top:25.221%;width:9.482%;aspect-ratio:163.848/200;opacity:0.18;transform:translate(-50%,-50%) rotate(-23.07deg)}
.artwork i:nth-child(23) {left:38.538%;top:50.085%;width:8.344%;aspect-ratio:144.187/176;opacity:0.18;transform:translate(-50%,-50%) rotate(-27.31deg)}
.artwork i:nth-child(24) {left:79.658%;top:15.886%;width:1.707%;aspect-ratio:29.493/36;opacity:0.18;transform:translate(-50%,-50%) rotate(-8.57deg)}
}
@media (max-width:700px) {
.artwork i:nth-child(1) {left:5.906%;top:16.152%;width:6.722%;aspect-ratio:26.216/32;opacity:0.14;transform:translate(-50%,-50%) rotate(-8.86deg)}
.artwork i:nth-child(2) {left:88.144%;top:49.269%;width:12.604%;aspect-ratio:49.155/60;opacity:0.14;transform:translate(-50%,-50%) rotate(27.17deg)}
.artwork i:nth-child(3) {left:19.341%;top:57.547%;width:22.687%;aspect-ratio:88.478/108;opacity:0.14;transform:translate(-50%,-50%) rotate(-2.96deg)}
.artwork i:nth-child(4) {left:36.470%;top:50.099%;width:27.728%;aspect-ratio:108.14/132;opacity:0.14;transform:translate(-50%,-50%) rotate(1.49deg)}
.artwork i:nth-child(5) {left:81.026%;top:16.621%;width:9.243%;aspect-ratio:36.047/44;opacity:0.14;transform:translate(-50%,-50%) rotate(-4.74deg)}
.artwork i:nth-child(6) {left:4.071%;top:34.773%;width:5.042%;aspect-ratio:19.662/24;opacity:0.14;transform:translate(-50%,-50%) rotate(-5.86deg)}
.artwork i:nth-child(7) {left:94.609%;top:39.923%;width:10.083%;aspect-ratio:39.324/48;opacity:0.14;transform:translate(-50%,-50%) rotate(8.78deg)}
.artwork i:nth-child(8) {left:19.473%;top:35.636%;width:18.485%;aspect-ratio:72.093/88;opacity:0.14;transform:translate(-50%,-50%) rotate(28.56deg)}
.artwork i:nth-child(9) {left:85.076%;top:22.072%;width:9.243%;aspect-ratio:36.047/44;opacity:0.14;transform:translate(-50%,-50%) rotate(12.07deg)}
.artwork i:nth-child(10) {left:20.199%;top:24.086%;width:22.687%;aspect-ratio:88.478/108;opacity:0.14;transform:translate(-50%,-50%) rotate(0.47deg)}
.artwork i:nth-child(11) {left:7.051%;top:20.451%;width:10.083%;aspect-ratio:39.324/48;opacity:0.14;transform:translate(-50%,-50%) rotate(3.77deg)}
.artwork i:nth-child(12) {left:97.245%;top:28.645%;width:18.485%;aspect-ratio:72.093/88;opacity:0.14;transform:translate(-50%,-50%) rotate(-27.16deg)}
.artwork i:nth-child(13) {left:71.328%;top:46.413%;width:12.604%;aspect-ratio:49.155/60;opacity:0.14;transform:translate(-50%,-50%) rotate(-13.93deg)}
.artwork i:nth-child(14) {left:71.843%;top:49.410%;width:5.042%;aspect-ratio:19.662/24;opacity:0.14;transform:translate(-50%,-50%) rotate(-18.42deg)}
}
</style>
