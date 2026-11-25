import { useState } from 'react'
import { Link } from 'react-router-dom'
import { consultasApi } from '../../api/consultasApi'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Insignia from '../../components/ui/Insignia'
import PlantillaLista from '../../components/plantillas/PlantillaLista'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { useSesion } from '../../hooks/useSesion'
import { cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'
import { formatearFecha } from '../../utils/formato'
import { enfocarPrimerError, erroresDeApi, validar } from '../../utils/validaciones'
import FormularioProduccion from './FormularioProduccion'
import { productoSeleccionado, reglasProduccion } from './reglasProductor'
import './Productor.css'

const ETIQUETA_PRODUCCION = { PLANIFICADA: 'Planificada', EN_CURSO: 'En curso', TERMINADA: 'Terminada', CANCELADA: 'Cancelada' }
const produccionVacia = { producto_id: '', fecha_inicio: '', fecha_disponibilidad_estimada: '', cantidad_estimada: '', ubicacion_produccion: '', observacion: '' }

/* Listado de producciones del productor con el alta en la misma página.
   La edición, los cambios de estado y la gestión de lotes están en la
   página de detalle (/productor/producciones/:id), no aquí. */
export default function MisProduccionesPagina() {
  const { tienePermiso } = useSesion() // oculta el alta si el permiso del dominio fue revocado
  const lista = useCargaApi(() => consultasApi.misProducciones(), [])
  const opciones = useCargaApi(() => productoresApi.opcionesProduccion(), [])
  const [formulario, setFormulario] = useState(produccionVacia)
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [errores, setErrores] = useState({})
  const [ocupado, setOcupado] = useState(false)
  const [mostrarAlta, setMostrarAlta] = useState(false)

  useMensajeTemporal(mensaje, setMensaje, 4500, Boolean(mensaje))

  const enviar = (e) => {
    e.preventDefault()
    const formularioHtml = e.currentTarget
    const seleccionado = productoSeleccionado(formulario, opciones.datos?.productos ?? [])
    const erroresCliente = validar(formulario, reglasProduccion(seleccionado?.unidad_medida === 'UNIDAD'))
    setErrores(erroresCliente)
    if (Object.keys(erroresCliente).length > 0) {
      setError('Revisa los campos marcados.')
      enfocarPrimerError(formularioHtml)
      return
    }
    setOcupado(true)
    setError('')
    productoresApi.crearProduccion(formulario)
      .then((r) => {
        setMensaje(r.message)
        setFormulario(produccionVacia)
        return lista.recargar()
      })
      .catch((falla) => {
        setErrores(erroresDeApi(falla))
        setError(falla.mensaje)
        enfocarPrimerError(formularioHtml)
      })
      .finally(() => setOcupado(false))
  }
  const limpiar = () => {
    setFormulario(produccionVacia)
    setErrores({})
    setError('')
  }

  const producciones = lista.datos?.producciones ?? []
  const activas = producciones.filter((p) => p.estado === 'PLANIFICADA' || p.estado === 'EN_CURSO').length

  const sinProducciones = producciones.length === 0
  const puedeRegistrar = tienePermiso('producciones.propias')

  return (
    <PlantillaLista
      titulo="Mis producciones"
      explicacion="Registra lo que produces y delega la gestión de cada ciclo a su página de detalle."
      accionPrincipal={puedeRegistrar && (
        <Boton onClick={() => setMostrarAlta((v) => !v)}>
          {mostrarAlta ? 'Cerrar' : '+ Nueva producción'}
        </Boton>
      )}
      resumen={{
        etiquetaTotal: 'producciones',
        total: producciones.length,
        chips: [
          { etiqueta: 'Activas', valor: activas, activo: false },
        ],
      }}
      cargando={lista.cargando}
      error={lista.error}
      vacio={!mostrarAlta && sinProducciones ? {
        titulo: 'Todavía no tienes producciones',
        mensaje: 'Pulsa «Nueva producción» para registrar la primera y empezar a ofrecer lotes a FEDER.',
      } : null}
    >
      {mostrarAlta && puedeRegistrar && (
        <section className="productor__panel productor__alta" aria-label="Registrar nueva producción">
          <h2>Registrar nueva producción</h2>
          <p className="productor__ayuda">
            ¿Tu producto no está en la lista (por ejemplo, mermelada de limón o de papaya)?<br />
            <Link to="/productor/propuestas">Propón un producto nuevo →</Link> para que FEDER lo revise y lo agregue.
          </p>
          {error && <Alerta tipo="error">{error}</Alerta>}
          {mensaje && <Alerta tipo="exito">{mensaje}</Alerta>}
          <form className="productor__formulario" onSubmit={enviar} aria-busy={ocupado} noValidate>
            <FormularioProduccion
              valores={formulario}
              alCambiar={setFormulario}
              errores={errores}
              alCorregir={(campo) => setErrores((actual) => ({ ...actual, [campo]: null }))}
              opciones={opciones.datos?.productos ?? []}
              idDetalle="alta-produccion"
            />
            {opciones.cargando && <p className="productor__ayuda">Cargando productos disponibles…</p>}
            <div className="productor__acciones">
              <Boton tipo="submit" cargando={ocupado}>Registrar producción</Boton>
              <Boton variante="fantasma" onClick={limpiar} disabled={ocupado}>Limpiar</Boton>
            </div>
          </form>
        </section>
      )}

      {!sinProducciones && (
        <div className="productor__tabla-contenedor" role="region" tabIndex={0} aria-label="Mis producciones">
          <table className="productor__tabla">
            <thead>
              <tr>
                <th scope="col">Código</th>
                <th scope="col">Producto</th>
                <th scope="col">Estado</th>
                <th scope="col">Disponible</th>
                <th scope="col">Producido</th>
                <th scope="col"><span className="solo-lector">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              {producciones.map((p) => (
                <tr key={p.id}>
                  <td>{p.codigo}</td>
                  <td>{p.producto}</td>
                  <td><Insignia tono={tonoEstado(p.estado) ?? 'info'}>{ETIQUETA_PRODUCCION[p.estado] ?? p.estado}</Insignia></td>
                  <td>{p.disponible_desde ? formatearFecha(p.disponible_desde) : '—'}</td>
                  <td>{cantidadConUnidad(p.producido, p.unidad_medida)}</td>
                  <td><Link to={`/productor/producciones/${p.id}`} className="boton boton--fantasma">Ver y gestionar</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </PlantillaLista>
  )
}
