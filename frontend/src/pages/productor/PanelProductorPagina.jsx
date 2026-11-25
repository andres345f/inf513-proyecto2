import { Link } from 'react-router-dom'
import { consultasApi } from '../../api/consultasApi'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import Tarjeta from '../../components/ui/Tarjeta'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { tonoEstado } from '../../utils/etiquetas'
import { formatearMoneda } from '../../utils/formato'
import './Productor.css'

const ETIQUETA_PRODUCCION = { PLANIFICADA: 'Planificada', EN_CURSO: 'En curso', TERMINADA: 'Terminada', CANCELADA: 'Cancelada' }
const ETIQUETA_COMPRA = { REGISTRADA: 'Por recibir', RECIBIDA_PARCIAL: 'Recepción parcial' }

/* El hub del productor responde de un vistazo: ¿qué produzco?, ¿qué lotes tengo
   disponibles?, ¿qué compró FEDER?, ¿qué debo entregar y cobrar? Cada bloque se
   muestra solo si el rol activo conserva el permiso de su dominio. */
export default function PanelProductorPagina() {
  const { tienePermiso } = useSesion()

  const consultaProducciones = () => (tienePermiso('producciones.propias') ? consultasApi.misProducciones() : Promise.resolve(null))
  const consultaLotes = () => (tienePermiso('producciones.propias') ? productoresApi.lotes() : Promise.resolve(null))
  const consultaVentas = () => (tienePermiso('ventas_feder.propias') ? consultasApi.misVentas() : Promise.resolve(null))

  const producciones = useCargaApi(consultaProducciones, [tienePermiso('producciones.propias')])
  const lotes = useCargaApi(consultaLotes, [tienePermiso('producciones.propias')])
  const ventas = useCargaApi(consultaVentas, [tienePermiso('ventas_feder.propias')])

  const puedeProducciones = tienePermiso('producciones.propias')
  const puedeVentas = tienePermiso('ventas_feder.propias')
  const cargando = (puedeProducciones && (producciones.cargando || lotes.cargando)) || (puedeVentas && ventas.cargando)

  if (cargando) return <Cargando texto="Cargando tu espacio productor…" />

  const activas = (producciones.datos?.producciones ?? []).filter((p) => p.estado === 'PLANIFICADA' || p.estado === 'EN_CURSO')
  const disponibles = (lotes.datos?.lotes ?? []).filter((l) => l.estado === 'DISPONIBLE')
  const compras = ventas.datos?.compras ?? []
  const porEntregar = compras.filter((c) => c.estado === 'REGISTRADA' || c.estado === 'RECIBIDA_PARCIAL')
  const porCobrar = ventas.datos?.indicadores?.por_cobrar
  const aviso = producciones.error || lotes.error || ventas.error

  return (
    <section className="productor">
      <EncabezadoPagina
        titulo="Mi panel productor"
        descripcion="Producción, lotes y ventas de tu actividad con FEDER."
      />

      {aviso && <Alerta tipo="aviso">Algunos datos no pudieron cargarse: {aviso}</Alerta>}

      <div className="productor__hub-rejilla">
        {puedeProducciones && producciones.datos && !producciones.error && (
          <Tarjeta titulo="Lo que estoy produciendo">
            {activas.length === 0 ? (
              <p className="productor__ayuda">Nada planificado o en curso en este momento.</p>
            ) : (
              <ul className="productor__hub-lista">
                {activas.slice(0, 4).map((p) => (
                  <li key={p.id}>
                    <Link to={`/productor/producciones/${p.id}`} className="productor__hub-fila">
                      <span>{p.producto}</span>
                      <Insignia tono={tonoEstado(p.estado) ?? 'info'}>{ETIQUETA_PRODUCCION[p.estado] ?? p.estado}</Insignia>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
            <Link to="/productor/producciones" className="boton boton--secundario">Ver todas mis producciones</Link>
          </Tarjeta>
        )}

        {puedeProducciones && lotes.datos && !lotes.error && (
          <Tarjeta titulo="Lotes disponibles">
            <p className="productor__hub-cifra">{disponibles.length}</p>
            {disponibles.length > 0 && (
              <p className="productor__ayuda">
                Último: {disponibles[0].codigo_lote} · {disponibles[0].producto}
              </p>
            )}
            <Link to="/productor/lotes" className="boton boton--secundario">Ver todos mis lotes</Link>
          </Tarjeta>
        )}

        {puedeVentas && ventas.datos && !ventas.error && (
          <Tarjeta titulo="Qué debo entregar a FEDER">
            {porEntregar.length === 0 ? (
              <p className="productor__ayuda">Sin compras pendientes de recepción.</p>
            ) : (
              <ul className="productor__hub-lista">
                {porEntregar.slice(0, 4).map((c) => (
                  <li key={c.id}>
                    <span>{c.codigo}</span>
                    <Insignia tono={c.estado === 'RECIBIDA_PARCIAL' ? 'aviso' : 'info'}>{ETIQUETA_COMPRA[c.estado]}</Insignia>
                  </li>
                ))}
              </ul>
            )}
            <Link to="/productor/ventas" className="boton boton--secundario">Ver mis ventas a FEDER</Link>
          </Tarjeta>
        )}

        {puedeVentas && ventas.datos && !ventas.error && (
          <Tarjeta titulo="Pagos por cobrar">
            <p className="productor__hub-cifra">{porCobrar == null ? 'Sin información' : formatearMoneda(porCobrar)}</p>
            {porCobrar > 0 && <p className="productor__ayuda">Compras recibidas cuya liquidación está pendiente.</p>}
            <Link to="/productor/ventas" className="boton boton--fantasma">Detalle de compras y pagos</Link>
          </Tarjeta>
        )}
      </div>


    </section>
  )
}
