import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useColoresTema } from '../../hooks/useColoresTema'
import { formatearNumero } from '../../utils/formato'
import Indicador from './Indicador'
import TarjetaGrafico from './TarjetaGrafico'
import './Panel.css'

const diaCorto = (dia) => new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: '2-digit' }).format(new Date(`${dia}T12:00:00`))

/** Flujo de accesos al sitio: visitas por día, páginas más visitadas e inicios de sesión. */
export default function SeccionAccesos({ datos }) {
  const c = useColoresTema()
  const globo = { backgroundColor: c.superficie, color: c.texto, border: `1px solid ${c['texto-suave']}` }
  const ejes = { fill: c['texto-suave'], fontSize: 12 }
  const fallidos = datos.inicios_de_sesion.reduce((s, d) => s + d.fallidos, 0)
  const exitosos = datos.inicios_de_sesion.reduce((s, d) => s + d.exitosos, 0)

  return (
    <section className="panel-seccion" aria-labelledby="titulo-accesos">
      <h2 id="titulo-accesos">Accesos al sitio</h2>
      <div className="indicadores">
        <Indicador etiqueta="Visitas a páginas" valor={formatearNumero(datos.total_visitas)} />
        <Indicador etiqueta="Inicios de sesión correctos" valor={formatearNumero(exitosos)} />
        <Indicador etiqueta="Intentos fallidos" valor={formatearNumero(fallidos)} tono={fallidos > 0 ? 'alerta' : 'normal'} />
      </div>

      <div className="graficos">
        <TarjetaGrafico titulo="Visitas por día" vacio={datos.visitas_por_dia.length === 0}
          resumen={datos.visitas_por_dia.map((d) => `${diaCorto(d.dia)} · ${formatearNumero(d.visitas)} visitas`)}>
          <ResponsiveContainer>
            <LineChart accessibilityLayer={false} data={datos.visitas_por_dia.map((d) => ({ ...d, etiqueta: diaCorto(d.dia) }))}>
              <CartesianGrid stroke={c.borde} strokeDasharray="3 3" />
              <XAxis dataKey="etiqueta" tick={ejes} stroke={c.borde} />
              <YAxis allowDecimals={false} tick={ejes} stroke={c.borde} />
              <Tooltip contentStyle={globo} itemStyle={{ color: c.texto }} formatter={(v) => [formatearNumero(v), 'Visitas']} />
              <Line type="monotone" dataKey="visitas" stroke={c.primario} strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </TarjetaGrafico>

        <TarjetaGrafico titulo="Páginas más visitadas" vacio={datos.paginas_mas_visitadas.length === 0}
          resumen={datos.paginas_mas_visitadas.map((p) => `${p.pagina}: ${p.visitas}`)}>
          <ResponsiveContainer>
            <BarChart accessibilityLayer={false} data={datos.paginas_mas_visitadas} layout="vertical" margin={{ left: 30 }}>
              <CartesianGrid stroke={c.borde} strokeDasharray="3 3" />
              <XAxis type="number" allowDecimals={false} tick={ejes} stroke={c.borde} />
              <YAxis type="category" dataKey="pagina" width={110} tick={ejes} stroke={c.borde} />
              <Tooltip contentStyle={globo} itemStyle={{ color: c.texto }} formatter={(v) => [formatearNumero(v), 'Visitas']} />
              <Bar dataKey="visitas" fill={c.primario} radius={[0, 4, 4, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </TarjetaGrafico>

        <TarjetaGrafico titulo="Inicios de sesión por día" vacio={datos.inicios_de_sesion.length === 0}
          resumen={datos.inicios_de_sesion.map((d) => `${diaCorto(d.dia)} · ${formatearNumero(d.exitosos)} correctos, ${formatearNumero(d.fallidos)} fallidos`)}>
          <ResponsiveContainer>
            <BarChart accessibilityLayer={false} data={datos.inicios_de_sesion.map((d) => ({ ...d, etiqueta: diaCorto(d.dia) }))}>
              <CartesianGrid stroke={c.borde} strokeDasharray="3 3" />
              <XAxis dataKey="etiqueta" tick={ejes} stroke={c.borde} />
              <YAxis allowDecimals={false} tick={ejes} stroke={c.borde} />
              <Tooltip contentStyle={globo} itemStyle={{ color: c.texto }} />
              <Legend />
              <Bar dataKey="exitosos" name="Correctos" stackId="a" fill={c.exito} />
              <Bar dataKey="fallidos" name="Fallidos" stackId="a" fill={c.error} />
            </BarChart>
          </ResponsiveContainer>
        </TarjetaGrafico>

        {datos.ips_con_mas_fallos.length > 0 && (
          <section className="tarjeta-grafico">
            <h2 className="tarjeta-grafico__titulo">Direcciones IP con más intentos fallidos</h2>
            <ol className="panel-lista">
              {datos.ips_con_mas_fallos.map((ip) => <li key={ip.ip}>{ip.ip}: {ip.fallidos} intentos</li>)}
            </ol>
          </section>
        )}
      </div>
    </section>
  )
}
